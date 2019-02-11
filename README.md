# WebPit

A self contained Rest API image/video converter to [WebP](https://developers.google.com/speed/webp/).

Images are converted to WebP, videos are converted to animated WebP (not WebM).

## Configuration

The following flags can be set for configuration:

```
WEBPIT_PORT = Port to run the API. Defaults to 8080.
WEBPIT_ADDRESS = The address to listen to, for IPv6 use [::]. Defaults to 0.0.0.0.
WEBPIT_DISABLE_INDEX = Disables the index form page for uploading a file conversion. Defaults to false, meaning the index is enabled.
WEBPIT_AUTH = A simple phrase required to authenticate all calls. Defaults to public.
WEBPIT_TTL = Seconds to keep the converted file after completion, in seconds. Defaults to 172800 (48 hours).
WEBPIT_MAX_SIZE = The max accepted file size in MB. Defaults to 20.
WEBPIT_MAX_SECS = The max number of seconds for videos. Defaults to 6 seconds.
WEBPIT_MAX_FILES = The max number of files to accept per request. Defaults to 10.
WEBPIT_CONCURRENCY = Max concurrent connections to handle. Defaults to 30.
```

## Usage

```
HTTP/1.1 GET /status

Response:

{
  "queued": 
  "processing": 
  "failed": 
  "completed": 
  "memory": "
}
```


```
HTTP/1.1 POST /convert

application/json
{
  "request": [
    {"content": BASE64},
    {"source": "URI" }
  ]
}

multipart/form-data
BINARY files
BINARY file
```

The server responds with an array of convertion Ids

```
{
  "response": [
    {
      "id": UID,
      "hash": MEDIA_SHA1,
      "source": "CONTENT"
    },
    {
      "id": UID,
      "hash": URI_SHA1,
      "source": "URI"
    }
  ]
}
```

The user then needs to query for the conversion.

```
HTTP/1.1 GET /status?id=UID

Response 202 queded and converting
Response 200 ready

{
  "id": UID,
  "hash": IMAGE_SHA1/URI_SHA1,
  "source": "CONTENT/URI",
  "status": queued/converting/completed/failed,

  "result": DOWNLOAD_URI
}
```

Once the download URI is available, it can be used to download the content.

```
HTTP1.1 GET /download?id=UID&token=XXXXXX

Ranges is supported, the server responds with:

  Accept-Ranges: bytes

Allowing the client to use:

  Range: bytes=0-1200

ETag and Last-Modified, as well as If-Range and partial content must be supported.
https://developer.mozilla.org/en-US/docs/Web/HTTP/Range_requests
```

Conversions are available for 48 hours after completion and then purged.


