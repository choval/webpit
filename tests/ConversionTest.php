<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use Clue\React\Block;
use React\Stream\ThroughStream;
use Webpit\Conversion;
use Webpit\Converter;

final class ConversionTest extends TestCase {

  private static $reactLoop;
  private static $converter;

  public static function setUpBeforeClass() {
    static::$reactLoop = Factory::create();
    static::$converter = new Converter(static::$reactLoop);
    // static::$reactLoop->run();
  }
  public function wait($promise, $timeout=2) {
    return Block\await( $promise, static::$reactLoop, $timeout );
  }
  public function converter() {
    return static::$converter;
  }




  public function fileProvider() {
    return [
        [ __DIR__.'/assets/test_jpg', 'image'],
        [ __DIR__.'/assets/test_mp4', 'video'],
    ];
  }


  public function textProvider() {
    return [
      ['The quick brown fox jumps over the lazy dog'],
    ];
  }



  /**
   * @dataProvider fileProvider
   */
  public function testCalculateFileHash($file) : void {
    $res = $this->wait( Conversion::calculateFileHash($file) );
    $hash = sha1_file($file);
    $this->assertEquals($hash, $res );
  }




  /**
   * @dataProvider fileProvider
   */
  public function testCalculateMime($file, $type) : void {
    $res = $this->wait( Conversion::calculateMime($file) );
    $this->assertEquals($type, substr($res, 0, 5));
  }




  /**
   * @dataProvider textProvider
   */
  public function testSaveStream($text) : void {
    $file = __DIR__.'/temp/testSaveStream';
    $chars = str_split($text);
    $stream = new ThroughStream;
    $prom = Conversion::saveStream($stream, $file);
    foreach($chars as $char) {
      $stream->write($char);
    }
    $stream->end();
    $res = $this->wait( $prom );
    $data = file_get_contents($file);
    unlink($file);
    $this->assertEquals($text, $data);
  }




  /**
   * @dataProvider textProvider
   * @depends testSaveStream
   */
  public function testSave($text) : void {
    $stream = new ThroughStream;
    $row = ['id'=>'test'];
    $prom = $this->converter()->create($stream, $row);
    $stream->end($text);
    $row = $this->wait($prom);
    $saved = $this->wait( $row->save() );
    $this->assertNotEmpty($saved);
  }



  /**
   * @dataProvider textProvider
   * @depends testSave
   */
  public function testGet($text) : void {
    $row = $this->wait( Conversion::get('test') );
    $this->assertNotEmpty($row);
    $data = file_get_contents($row->getInputPath());
    $this->assertEquals($text, $data);
  }




  /** 
   * @depends testGet
   */
  public function testGetMissing() : void {
    $this->expectException(Exception::class);
    $this->wait( Conversion::get('fail') );
  }




  /**
   * @depends testGet
   */
  public function testDelete() : void {
    $row = $this->wait( Conversion::get('test') );
    $res = $this->wait( $row->delete() );
    $this->assertNotFalse($res);
  }





  /**
   * @dataProvider textProvider
   * @depends testSaveStream
   * @depends testDelete
   * @depends testSave
   */
  public function testCreate($text) : void {
    $chars = str_split($text);
    $stream = new ThroughStream;
    $row = [];
    $prom = $this->converter()->create($stream, $row);
    foreach($chars as $char) {
      $stream->write($char);
    }
    $stream->end();
    $row = $this->wait($prom);
    $inputPath = $row->getInputPath();
    $data = file_get_contents($inputPath);
    unlink($inputPath);
    $this->assertEquals($text, $data);
    $this->wait($row->delete());
  }





  /**
   * @dataProvider fileProvider
   */
  public function testConvertImage($file, $type) : void {
    if($type != 'image') {
      $this->assertTrue(true);
      return;
    }
    $target = $file.'.webp';
    $out = $this->wait( Conversion::convertImage($file, $target) , 10);
    $outsize = filesize($out);
    $filesize = filesize($file);
    unlink($out);
    $this->assertLessThan( $filesize, $outsize);
    $this->assertNotEmpty( $filesize );
  }


  
  /**
   * @dataProvider fileProvider
   */
  public function testConvertVideo($file, $type) : void {
    if($type != 'video') {
      $this->assertTrue(true);
      return;
    }
    $target = $file.'.webp';
    $out = $this->wait( Conversion::convertVideo($file, $target) , 1800);
    $size = filesize($out);
    unlink($out);
    $this->assertNotEmpty( $size );
  }



  /**
   * @dataProvider fileProvider
   * @depends testSave
   * @depends testDelete
   * @depends testConvertImage
   */
  public function testConvertObject($file, $type) : void {
    // Skips the video as its too large
    if($type != 'image') {
      $this->assertTrue(true);
      return;
    }
    $row = new Conversion;
    $data = file_get_contents($file);
    $this->wait( $row->setInput( $data ) , 10);
    $this->wait( $row->convert() , 10);
    $this->assertEquals( 'completed', $row->getStatus() );
    $this->wait( $row->delete() );
  }




}


