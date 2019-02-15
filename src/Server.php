<?php
namespace Webpit;

use React\EventLoop\LoopInterface;
use React\Socket\Server as SocketServer;
use \React\Filesystem\Filesystem;

use React\Http\StreamingServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use WyriHaximus\React\Http\Middleware\WithHeadersMiddleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

use React\Http\Response;
use React\Stream\ThroughStream;
use React\Promise\Deferred;
use React\Promise;
use React\Promise\FulfilledPromise as ResolvedPromise;
use function React\Promise\resolve;

use Exception;

final class Server {

  private $reactLoop;
  private $config;
  private $socket;
  private $server;
  private $fs;
  private $root;
  private $database;
  private $converter;


  /**
   *
   * Constructor
   *
   */
  public function __construct(LoopInterface $loop) {
    $this->reactLoop = $loop;
    $this->root = getcwd();

    $this->config = [];
    $this->config['port'] = ($port = (int)getenv('WEBPIT_PORT')) ? $port : 8080;
    $this->config['address'] = ($address = getenv('WEBPIT_ADDRESS')) ? $address : '0.0.0.0';
    $this->config['disable_index'] = empty(getenv('WEBPIT_DISABLE_INDEX')) ? false : true;
    $this->config['auth'] = getenv('WEBPIT_AUTH');
    $this->config['concurrency'] = ($concurrency = (int)getenv('WEBPIT_CONCURRENCY')) ? $concurrency : 30;
    $this->config['ttl'] = ($ttl = (int)getenv('WEBPIT_TTL')) ? $ttl : TTL;
    $this->config['max_size'] = ($max_size = (int)getenv('WEBPIT_MAX_SIZE')) ? $max_size : MAX_SIZE;
    $this->config['max_secs'] = ($max_secs = (int)getenv('WEBPIT_MAX_SECS')) ? $max_secs : MAX_SECS;
    $this->config['max_files'] = ($max_files = (int)getenv('WEBPIT_MAX_FILES')) ? $max_files : MAX_FILES;

    // Filesystem
    $this->fs = Filesystem::create( $this->reactLoop );
    $this->converter = new Converter($loop, $this->config);

    // Socket
    $this->socket = new SocketServer( $this->config['address'].':'.$this->config['port'] , $this->reactLoop );

    // Server
    $this->server = new StreamingServer([
          new WithHeadersMiddleware([
            'X-Powered-By' => 'WebPit/0.1 (https://publitar.com/p/webpit)',
          ]),
          function (ServerRequestInterface $request, callable $next) {
            return resolve($next($request))
              ->then(function(ResponseInterface $response) use ($request) {
                $method = $request->getMethod();
                $path = $request->getUri()->getPath();
                $status = $response->getStatusCode();
                $date = gmdate('Y-m-d H:i:s');
                echo "[$date] $status - $method $path\n";
                return resolve($response);
              });
          },
          new LimitConcurrentRequestsMiddleware( $this->config['concurrency'] ),
          new RequestBodyBufferMiddleware( $this->config['max_size'] * 1024 * 1024 ),
          new RequestBodyParserMiddleware( $this->config['max_size'] * 1024 * 1024, 10),
          function (ServerRequestInterface $request) {
            return $this->handler($request);
          }
      ]);
    $this->server->on('error', function(Exception $e) {
      do {
        echo "SERVER ERROR: ".$e->getMessage()."\n";
        echo "---- DEBUG ----\n";
        print_r($e->getTraceAsString());
        echo "\n";
      } while( ($e = $e->getPrevious() ));
    });

  }




  /**
   *
   * Destructor
   *
   */
  public function __destruct() {
  }




  /** 
   *
   * Starts the server
   *
   */
  public function start() {
    $this->server->listen($this->socket);
    // TODO: Echo
  }




  /**
   *
   * Handler
   *
   */
  public function handler(ServerRequestInterface $request) {
    $path = $request->getUri()->getPath();
    try {
      switch($path) {
        case '/':
          $path = '/index.html';
        case '/favicon.ico':
        case '/robots.txt':
          $file = basename($path);
          return $this->rawFile( dirname(__DIR__).'/static/'.$file );
        case '/README.md':
          return $this->rawFile( dirname(__DIR__).'/README.md' );
        case '/convert':
          $method = $request->getMethod();
          if($method == 'POST') {
            return $this->handleConvert($request);
          }
        case '/download':
          return $this->handleDownload($request);
        case '/status':
          return $this->handleStatus($request);
        case '/query':
          return $this->handleQuery($request);
      }
    } catch(\Exception $e) {
      return $this->jsonResponse($e->getCode(), $e->getMessage());
    }
    return $this->jsonResponse(404, ['error'=>'not found']);
  }




  /**
   *
   * Calculates the mime using the file name
   *
   */
  static public function mimeByPath(string $file) : string {
    if(strpos($file,'/') === false && strpos($file,'.') === false) {
      $ext = $file;
    } else {
      $ext = pathinfo( $file, PATHINFO_EXTENSION);
    }
    $ext = strtolower($ext);
    $mime = 'application/octet-stream';
    $mimes = [
      'md' => 'text/plain',
      'css' => 'text/css',
      'gif' => 'image/gif',
      'html' => 'text/html',
      'htm' => 'text/htm',
      'ico' => 'image/x-icon',
      'jpeg' => 'image/jpeg',
      'jpg' => 'image/jpeg',
      'jpe' => 'image/jpeg',
      'js' => 'application/javascript',
      'json' => 'application/json',
      'pdf' => 'application/pdf',
      'png' => 'image/png',
      'svg' => 'image/svg+xml',
      'xls' => 'application/vnd.ms-excel',
      'webp' => 'image/webp',
    ];
    return $mimes[$ext] ?? $mime;
  }




  /**
   *
   * Returns a static file
   *
   */
  public function rawFile(string $file, array $headers=[], int $code=200) {
    $defer = new Deferred;
    if(  !isset($headers['Content-Type'])
      && !isset($headers['content-type'])
      && !isset($headers['Content-type']) ) {
      $headers['Content-Type'] = static::mimeByPath($file);
    }
    $this->fs->file($file)->exists()
      ->then(
        function() use ($file) {
          return $this->fs->file($file)->open('r');
        },
        function($e) use ($defer) {
          $defer->reject($e);
        }
      )
      ->then(
        function($fileStream) use ($defer, $headers, $code) {
          $stream = new ThroughStream;
          $response = new Response($code, $headers, $stream);
          $fileStream->pipe($stream);
          $defer->resolve( $response );
        },
        function($e) use ($defer) {
          $defer->reject($e);
        }
      );
    return $defer->promise();
  }




  /**
   *
   * Handles conversion
   *
   */
  public function handleConvert(ServerRequestInterface $req) {
    $contentType = $req->getHeaderLine('Content-Type') ?? false;
    if(!$contentType) {
      return new ResolvedPromise( $this->jsonResponse( 400, ['error'=>'bad request'] ) );
    }
    $defer = new Deferred;
    // Retrieves files from the request
    $files = [];
    if($contentType == 'application/json') {
      $body = $req->getParsedBody();
      foreach($body['request'] as $bodyreq) {
        if(isset($bodyreq['content'])) {
          $files[] = base64_decode($bodyreq['content']);
        }
      }
    }
    else if(strpos($contentType, 'multipart/form-data') !== false) {
      $uploads = $req->getUploadedFiles();
      $uploadFiles = $uploads['files'] ?? [];
      if(isset($uploads['file'])) {
        $uploadFiles[] = $uploads['file'];
      }
      foreach($uploadFiles as $uploadFile) {
        if($uploadFile instanceof UploadedFileInterface) {
          if($uploadFile->getError() === \UPLOAD_ERR_OK) {
            $files[] = $uploadFile->getStream();
          }
        }
      }
    }

    // User agent and remote address
    $userAgent = $req->getHeaderLine('User-Agent');
    if(is_array($userAgent)) {
      $userAgent = current($userAgent);
    }
    $server = $req->getServerParams();
    $remoteAddress = $server['REMOTE_ADDR'];

    // Handle each of the files
    $promises = [];
    foreach($files as $file) {
      $row = [];
      $row['remote_address'] = $remoteAddress;
      $row['user_agent'] = $userAgent;

      $obj = new Conversion($row);
      $promises[] = $obj->setInput($file);
    }
    if(empty($promises)) {
      return $defer->resolve( $this->jsonResponse( 400, ['error'=>'bad request']) );
    }
    Promise\all($promises)
      ->then(function($objs) use ($defer) {
        $res = [];
        $res['conversions'] = [];

        foreach($objs as $obj) {
          $tmp = [];
          $tmp['created'] = gmdate('c', $obj->getCreated() );
          $tmp['download_token'] = $obj->getDownloadToken();
          $tmp['id'] = $obj->getId();
          $tmp['input_hash'] = $obj->getInputHash();
          $tmp['input_source'] = $obj->getInputSource();
          $tmp['status'] = $obj->getStatus();

          $res['conversions'][] = $tmp;
        }
        $defer->resolve( $this->jsonResponse( 201, $res ) );
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Handles query
   *
   */
  public function handleQuery(ServerRequestInterface $req) {
    $id = $req->getQueryParams()['id'] ?? false;
    if(empty($id)) {
      return RejectedPromise( $this->jsonResponse( 400, ['error'=>'bad request']) );
    }
    $defer = new Deferred;
    Conversion::get($id)
      ->then(function($obj) use ($defer) {
        $tmp = [];
        $tmp['id'] = $obj->getId();
        $tmp['input_hash'] = $obj->getInputHash();
        $tmp['input_source'] = $obj->getInputSource();
        $tmp['created'] = gmdate('c', $obj->getCreated() );
        $tmp['status'] = $obj->getStatus();
        switch($tmp['status']) {
          case 'queued':
          case 'converting':
          case 'pending':
            $code = 202;
            break;
          case 'completed':
            $code = 200;
            break;
          case 'failed':
            $code = 204;
            $tmp['error'] = $obj->getError();
            break;
        }
        $defer->resolve( $this->jsonResponse( $code, $tmp) );
      })
      ->otherwise(function($e) use ($defer) {
        $defer->resolve( $this->jsonResponse( $e->getCode(), $e->getMessage() ) );
      });
    return $defer->promise();
  }




  /**
   *
   * Handles download
   *
   */
  public function handleDownload(ServerRequestInterface $req) {
    $get = $req->getQueryParams();
    $id = $get['id'] ?? false;
    $token = $get['token'] ?? $get['download_token'] ?? false;
    if(empty($id) || empty($token)) {
      return ResolvedPromise( $this->jsonResponse( 400, ['error'=>'bad request'] ) );
    }
    $defer = new Deferred;
    Conversion::get($id)
      ->then(function($obj) use ($token, $defer) {
        if($obj->getDownloadToken() != $token) {
          return $defer->resolve( $this->jsonResponse(401, ['error'=>'forbidden'] ) );
        }
        $status = $obj->getStatus();
        switch($status) {
          case 'converting':
          case 'queued':
            return $this->jsonResponse(201, $status);
            break;
          case 'failed':
            return $this->jsonResponse(500, $status);
            break;
        }
        $headers = [
          'Content-Disposition' => 'inline; name="'.$obj->getId().'"; filename="'.basename($obj->getOutputPath()).'"',
               'Content-Length' => $obj->getOutputSize(),
                'Last-Modified' => gmdate('r', $obj->getChecked()),
                      'Expires' => gmdate('r', $obj->getExpires()),
                         'ETag' => $obj->getOutputHash(),
        ];
        return $this->rawFile( $obj->getOutputPath(), $headers );
      })
      ->then(function($res) use ($defer) {
        $defer->resolve($res);
      })
      ->otherwise(function($e) use ($defer) {
        $defer->reject($e);
      });
    return $defer->promise();
  }




  /**
   *
   * Handles status
   *
   */
  public function handleStatus(ServerRequestInterface $req) {

  }




  /**
   *
   * Generates a response for return
   *
   */
  public function jsonResponse(int $code, $msg) : Response {
    return new Response(
      $code,
      ['Content-Type'=>'application/json'],
      json_encode($msg)
    );
  }



}

