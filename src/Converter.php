<?php
namespace Webpit;


use React\EventLoop\LoopInterface;
use React\Filesystem\Filesystem;

use Clue\React\Block;

use React\Promise\Deferred;
use React\Promise;
use React\Promise\FulfilledPromise as ResolvedPromise;



class Converter {


  private $reactLoop;
  private $fs;
  private $config;
  private $root;
  private $filesRoot;
  private $dataRoot;

  private $conversions = [];
  private $status = [];
  private $diskFreeSpace = 0;


  /**
   *
   * Construct
   *
   */
  public function __construct(LoopInterface $loop, array $config=[]) {
    $this->reactLoop = $loop;

    $this->root = getcwd();
    $this->filesRoot = $this->root.'/files';
    $this->dataRoot = $this->root.'/data';

    $this->config['ttl'] = $config['ttl'] ?? TTL;
    $this->config['max_size'] = $config['max_size'] ?? MAX_SIZE;
    $this->config['max_secs'] = $config['max_secs'] ?? MAX_SECS;
    $this->config['max_files'] = $config['max_files'] ?? MAX_FILES;

    $this->fs = Filesystem::create( $this->reactLoop );

    Conversion::setFilesystem( $this->fs );
    Conversion::setLoop( $this->reactLoop );
    Conversion::setConverter( $this );


    // Scans existing conversions
    $this->reactLoop->addTimer(2, function() {
      $files = glob('data/*.json');
      $promises = [];

      foreach($files as $file) {
        $id = str_replace('.json','',basename($file));
        if(!isset($this->conversions[$id])) {
          Conversion::get($id);
        }
      }

      Conversion::calculateDiskFreeSpace()
        ->then(function($space) { 
          $this->diskFreeSpace = $space;
        });
    });


    // Check disk free
    $this->reactLoop->addPeriodicTimer(60, function() {
      Conversion::calculateDiskFreeSpace()
        ->then(function($space) { 
          $this->diskFreeSpace = $space;
        });
    });


    // Regularly loop through active conversions
    $this->reactLoop->addPeriodicTimer(1, function() {
      $status = [
           'pending' => 0,
            'queued' => 0,
        'converting' => 0,
         'completed' => 0,
            'failed' => 0,
      ];
      foreach($this->conversions as $conv) {
        if($conv->isExpired()) {
          $conv->delete();
        } else {
          $objStatus = $conv->getStatus();
          $status[ $objStatus ]++;
          if($objStatus == 'queued') {
            $conv->convert();
          }
        }
      }
      $this->status = $status;
    });


  }




  /**
   *
   * Returns status
   *
   */
  public function getStatus() {
    return $this->status;
  }




  /**
   *
   * Gets the free space
   *
   */
  public function getDiskFreeSpace() {
    return $this->diskFreeSpace;
  }




  /**
   *
   * Adds a conversion
   *
   */
  public function addConversion(Conversion $obj) {
    $id = $obj->getId();
    $this->conversions[$id] = $obj;
    return $this;
  }




  /**
   *
   * Deletes a conversion
   *
   */
  public function delConversion(Conversion $obj) {
    $id = $obj->getId();
    unset($this->conversions[$id]);
    return $this;
  }




  /**
   * 
   * Returns the time to live
   *
   */
  public function getTTL() : int {
    return $this->config['ttl'];
  }
  public function getTimeToLive() : int {
    return $this->getTTL();
  }




  /**
   * 
   * Returns the max seconds for videos
   *
   */
  public function getMaxSecs() : int {
    return $this->conifg['max_secs'];
  }




  /**
   *
   * Destructor
   *
   */
  public function __destruct() {
    /*
    if($this->database) {
      $this->database->quit();
    }
    */
  }




  /**
   *
   * Creates a conversion entry from a stream
   *
   */
  public function create($stream, array $row=[]) {
    $defer = new Deferred;
    $conv = new Conversion($row);
    $conv->setInput($stream)
      ->then(function($size) use ($defer, $conv) {
        $defer->resolve( $conv );
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  
}

