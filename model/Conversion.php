<?php

/**
* Name: Conversion
* Description: This class handles video conversion and thumbnails generation
* @author: Saqib Razzaq
* @since: v1, Feburary, 2019
* @link: https://github.com/briskLimbs/briskLimbs/blob/master/model/Conversion.php
*/

class Conversion {

  /*
  * Holds global settings object
  */
  private $settings;

  /*
  * Holds ffmpeg path
  */
  private $ffmpeg;

  /* 
  * Holds ffprobe path
  */
  private $ffprobe;

  /*
  * Holds filename to be processed
  */
  private $filename;

  /*
  * Holds directory to store output files into
  */
  private $directory;

  /*
  * Holds complete path of file to be processed
  */
  private $path;

  /*
  * Holds Logs object
  */
  private $logs;

  /*
  * Holds width of video
  */
  private $width;

  /*
  * Holds height of video
  */
  private $height;

  function __construct($filename, $directory, $path, $logs) {
    global $settings;
    $this->settings = $settings; 
    $this->ffmpeg = $this->settings->get('ffmpeg');
    $this->ffprobe = $this->settings->get('ffprobe');
    $this->filename = $filename;
    $this->directory = $directory; // e.g 2017/04/28
    $this->path = $path;
    $this->logs = $logs;
    $this->width = false;
    $this->height = false;
    $this->preset = $this->settings->get('ffmpeg_preset');
    $this->videoCodec = $this->settings->get('video_codec');
    $this->audioCodec = $this->settings->get('audio_codec');
    $this->enableWatermark = $this->settings->enabled('enable_watermark');
  }

  /**
  * Extract video details
  * @param: { $path } { string } { path of video file }
  * @param: { $jsonDecode } { boolean } { converts json if true }
  * @return: { array / json } 
  */
  public function details($path, $jsonDecode = true) {
    $command = "$this->ffprobe -v quiet -print_format json -show_format -show_streams $path";
    $details = shell_exec($command);
    return $jsonDecode ? json_decode($details, true)['streams'] : $details;
  }

  /**
  * Extract video duration
  * @param: { $path } { string } { path of video file }
  * @return: { integer }
  */
  public function duration($path) {
    $command = "$this->ffprobe -v quiet -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 $path";
    $duration = shell_exec($command);
    return is_numeric(trim($duration)) ? $duration : false;
  }

  /**
  * Creates video thumbnails
  * @param: { $path } { string } { path of video file }
  * @return: { array }
  */
  public function createThumbnails($path) {
    $generatedThumbs = array();
    $thumbOptions = array(
      "original" => $this->width . 'x' . $this->height,
      'lowest' => '168x105',
      'small' => '416x260',
      'medium' => '632x395',
      'highest' => '768x432'
      );

    foreach ($thumbOptions as $optionName => $size) {
      $thumbOutputPath = str_replace('//', '/', THUMBNAILS_DIRECTORY . '/' . $this->directory . '/' . $this->filename . '_' . $optionName . '.jpg');
      $this->remove($thumbOutputPath);
      $thumbsCommand = "$this->ffmpeg -deinterlace -an -ss 00:00:02 -i $path -f image2 -t 1 -r 1 -y -s $size -q:v 1 $thumbOutputPath";
      $this->logs->write("Command for thumb : $optionName ($size) is : $thumbsCommand");
      shell_exec($thumbsCommand);
      if (file_exists($thumbOutputPath)) {
        $this->logs->write("Successfully generated $optionName thumb");
        $generatedThumbs[$optionName] = $thumbOutputPath;
      }
    }
    return $generatedThumbs;
  }

  /**
  * Determines video qualities that can be generated for given video
  * @param: { $width } { integer } { width of video }
  * @param: { $height } { integer } { height of video } 
  * @return: { array }
  */
  public function possibleQualities($width, $height) {
    $basicQualities = array('240', '360', '480', '720', '1080');
    $dimensions = array(
      '240' => array(
        'width' => '424', 
        'video_bitrate' => $this->settings->get('basic_vbitrate'), 
        'audio_bitrate' => $this->settings->get('basic_abitrate')
      ), 
      '360' => array(
        'width' => '640', 
        'video_bitrate' => $this->settings->get('normal_vbitrate'), 
        'audio_bitrate' => $this->settings->get('normal_abitrate')
      ), 
      '480' => array(
        'width' => '848', 
        'video_bitrate' => $this->settings->get('sd_vbitrate'), 
        'audio_bitrate' => $this->settings->get('sd_abitrate')
      ), 
      '720' => array(
        'width' => '1280', 
        'video_bitrate' => $this->settings->get('hd_vbitrate'), 
        'audio_bitrate' => $this->settings->get('hd_abitrate')
      ),
      '1080' => array(
        'width' => '1920', 
        'video_bitrate' => $this->settings->get('fullhd_vbitrate'), 
        'audio_bitrate' => $this->settings->get('fullhd_abitrate')
      )
    );
    $possibleQualities = array();
    foreach ($basicQualities as $quality) {
      if ($this->settings->get("quality_{$quality}") != 'yes') { continue; }
      if ($quality <= $height) {
        $finalDimensions = $dimensions[$quality];
        if ($finalDimensions['width'] > $width) {
          $finalDimensions['width'] = $width;
        }

        $possibleQualities[$quality] = $finalDimensions;
      }
    }

    if (empty($possibleQualities)) {
      $possibleQualities['240'] = $dimensions['240'];
    }

    return $possibleQualities;
  }

  /**
  * Handles main process of video conversion
  * This function extracts video details, determines output qualities, generates thumbnails
  * and generates video
  * @return: { array }
  */
  public function process() {      
    $this->logs->write("Log file for video : $this->filename");
    $this->logs->write("Fetching video details...");
    $rawVideoDetails = $this->details($this->path, false);
    $videoDetails = json_decode($rawVideoDetails, true)['streams'];
    $this->logs->write("Video details : $rawVideoDetails");
    
    if ($videoDetails[0]['codec_type'] == 'video') {
      $videoSection = $videoDetails[0];
      $audioSection = $videoDetails[1];
    } else {
      $videoSection = $videoDetails[1];
      $audioSection = $videoDetails[0];
    }

    if (!isset($videoSection['duration'])) {
      $videoSection['duration'] = timeSeconds($videoSection['tags']['DURATION']);
    }
    
    $this->width = $videoSection['width'];
    $this->height = $videoSection['height'];

    # ffmpeg -i input.avi -s 720x480 -c:a copy output.mkv
    
    $resoloutionCommands = array();
    $outputFiles = array();
    $outputFiles['details']['duration'] = $videoSection['duration'];
    $this->logs->write("Getting ready to generate thumbs");
    $generatedThumbs = $this->createThumbnails($this->path);
    $this->logs->write("Generated thumbs are : \n" . implode("\n", $generatedThumbs));
    
    if ($this->enableWatermark) {
      $this->logs->write("Getting ready to add watermark");
      $this->watermark($this->path, str_replace($this->filename, $this->filename . '_wm', $this->path));
      $this->logs->write("Watermark has been added");
    }

    $this->logs->write("Listing possible video qualities to be converted");
    $pendingResouloutions = $this->possibleQualities($this->width, $this->height);

    $finalPossibleResolotuions = array_keys($pendingResouloutions);
    $this->logs->write("Possible video qualties to be converted are : \n" . implode("\n", $finalPossibleResolotuions));
    $outputFiles['details']['possibleQualities'] = implode(',', $finalPossibleResolotuions);
    $this->logs->write("Looping through qualities and building commands");
    
    foreach ($pendingResouloutions as $height => $resoloution) {
      $currentOutputFile = VIDEOS_DIRECTORY . '/' . $this->directory . '/' . $this->filename . '-' . $height . '.mp4';
      $outputFiles['files'][] = $currentOutputFile;
      $this->remove($currentOutputFile);

      $width = $resoloution['width'];
      $videoBitrate = $resoloution['video_bitrate'];
      $audioBitrate = $resoloution['audio_bitrate'];

      $currentCommand = "$this->ffmpeg -i $this->path -vcodec {$this->videoCodec} -vprofile baseline -preset {$this->preset} -b:v {$videoBitrate}k -maxrate {$videoBitrate}k -vf scale=$width:$height -threads 0 -acodec {$this->audioCodec} -ab {$audioBitrate}k $currentOutputFile 2>&1";

      $this->logs->write("Command for resoloution {$height}p is : $currentCommand");
      $resoloutionCommands[] = $currentCommand;
    }

    foreach ($resoloutionCommands as $key => $currentVideoResoloutionCommand) {
      $this->logs->write("Executing command: $currentVideoResoloutionCommand");
      $commandOutput = shell_exec($currentVideoResoloutionCommand); 
      $this->logs->write("Command output \n $commandOutput");
    }

    foreach ($outputFiles['files'] as $key => $outputFile) {
      if (file_exists($outputFile) && $this->duration($outputFile)) {
        $outputFiles['files'][$key] = array('status' => 'success', 'file' => $outputFile);
      } else {
        $outputFiles['files'][$key] = array('status' => 'failed', 'file' => $outputFile);
      }
    }

    $this->logs->write("File conversion is done and returing output to other function");    
    return $outputFiles;
  }

  /**
  * Remove a file 
  * @param: { $path } { string } { path to be removed }
  */
  private function remove($path) {
    if (file_exists($path)) {
      unlink($path);
    }
  }

  function watermark($input, $output) {
    $placement = $this->settings->get('watermark_placement');
    $watermark = MEDIA_DIRECTORY . '/watermark.png';
    if (file_exists($watermark)) {
      switch ($placement) {
        case 'right:bottom':
          $placement = ' "overlay=main_w-overlay_w-5:main_h-overlay_h-50" ';
          break;
        case 'left:top':
          $placement = ' "overlay=5:5" '; 
          break;
        case 'right:top':
          $placement = ' "overlay=main_w-overlay_w-5:5" ';
          break;
        case 'center:top':
          $placement = ' "overlay=main_w/2-overlay_w/2" ';
          break;
        case 'left:bottom':
          $placement = ' "overlay=5:main_h-overlay_h" ';
          break;
        case 'center:center':
          $placement = ' "overlay=(W-w)/2:(H-h)/2" ';
          break;
        default:
          # code...
          break;
      }

      $command = "$this->ffmpeg -i $input -i $watermark -filter_complex $placement $output";

      $this->logs->write("Watermark command: $command");
      $commandOutput = shell_exec($command);
      $this->logs->write("Watermark command output \n $commandOutput");

      // if watermarked successfully then replace original with watermarked version
      if (file_exists($output)) {
        unlink($input);
        rename($output, $input);
        return $input;
      }
    }
  }
}