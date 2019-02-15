# WebPit

A self contained Rest API image & video converter to [WebP](https://developers.google.com/speed/webp/).  
Images are converted to WebP, videos are converted to animated WebP (not WebM).


## API


Requesting a file conversion

```
HTTP/1.1 POST /convert

application/json
{
  "request": [
    {
      "content": [BASE64_IMAGE/VIDEO]
    }
  ]
}

multipart/form-data
[FILES file]

multipart/form-data
[FILES files]
```


The server responds with an array of convertion Ids
```
HTTP CODE 201

{
  "conversions": [
    {
      "created": "2019-02-02T12:12:12+00:00",
      "download_token": [TOKEN_FOR_DOWNLOAD_LINK_ONLY_GIVEN_HERE],
      "id": [UID],
      "INPUT_hash": [MEDIA_SHA1],
      "input_source": "CONTENT",
      "status": [pending/queued/converting/completed/failed]
    }
  ]
}


{
  "conversions": [
    {
      "created": "2019-02-02T12:12:12+00:00",
      "download_token": [TOKEN_FOR_DOWNLOAD_LINK_ONLY_GIVEN_HERE],
      "id": UID,
      "input_hash": [MEDIA_SHA1],
      "input_source": "STREAM",
      "status": [pending/queued/converting/completed/failed]
    }
  ]
}
```


The client then queries the conversion.

```
HTTP/1.1 GET /query?id=[UID]

Response 202 queded and converting
Response 200 completed
Response 204 failed

{
  "id": UID,
  "input_hash": IMAGE_SHA1,
  "input_source": [CONTENT/STREAM],
  "created": "2019-01-01T01:01:01+00:00",
  "status": [pending/queued/converting/completed/failed]
}
```


Once the conversion is completed, the result file can be downloaded using the `download_token`.

```
HTTP1.1 GET /download?id=[UID]&token=[DOWNLOAD_TOKEN]

ETag: [OUTPUT_HASH]
Last-Modified: [CONVERSION_COMPLETED_DATE]
Expires: [AFTER_THIS_DATE_FILE_WILL_BE_DELETED]
```

```
TO BE IMPLEMENTED:
-----------------------------------------------
Ranges is supported, the server responds with:
Accept-Ranges: bytes

Allowing the client to use:
Range: bytes=0-1200
```

[Range requests reference](https://developer.mozilla.org/en-US/docs/Web/HTTP/Range_requests)

Conversions are available for 48 hours (default, can be configured on TTL) after completion and then purged.


A server wide status can be checked using the status path.

```
HTTP1.1 GET /status

{
  "conversions": {
    "completed": 0,
    "converting": {
      "images": 0,
      "videos": 0,
    },
    "failed": 0,
    "pending": 0,
    "queued": 0
  },
  "server": {
    "version": [VERSION],
    "disk": [REMAINING_DISK_BYTES],
    "config": {
      "ttl": [WEBPIT_TTL],
      "max_size": [WEBPIT_MAX_SIZE],
      "max_files": [WEBPIT_MAX_FILES],
      "max_secs": [WEBPIT_MAX_SECS],
      "max_width": [WEBPIT_MAX_WIDTH],
      "quality": [WEBPIT_QUALITY]
    }
  }
}

```


## Authentication

The API can be configured with a key for `/convert` and `/status`.

Set the environment variable WEBPIT_AUTH with your desired key, and pass this key in the `Authorization` header like this:

```
Authorization: Basic [BASE64_AUTH_KEY]
```

If the key is set, the `/query` will need the authorization header or the download_token as a query param `token`.

The `/download` path doesn't need the authorization header, but the download_token is required.



## Configuration

The following environment variables can be set. Pass them to the docker image.

```
WEBPIT_PORT = Port to run the API. Defaults to 8080.
WEBPIT_ADDRESS = The address to listen to, for IPv6 use [::]. Defaults to 0.0.0.0 (IPv4).
WEBPIT_DISABLE_INDEX = Disables the index form page for uploading a file conversion. Defaults to false, meaning the index is enabled.
WEBPIT_AUTH = A simple phrase required to authenticate all calls. Defaults to public.
WEBPIT_TTL = Seconds to keep the converted file after completion, in seconds. Defaults to 172800 (48 hours).
WEBPIT_MAX_SIZE = The max accepted file size in MB. Defaults to 20.
WEBPIT_MAX_FILES = The max number of files to accept per request. Defaults to 10.
WEBPIT_CONCURRENCY = Max concurrent connections to handle. Defaults to 30.
WEBPIT_MAX_SECS = The max number of seconds for animated WebP. Defaults to 6 seconds.
WEBPIT_MAX_WIDTH = The max width for WebP. Defaults to 1080.
WEBPIT_QUALITY = Quality for WebP. Defaults to 70.
WEBPIT_MAX_CONVERSIONS = Simultaneos conversions. Defaults to 1.
```

## Docker

Requires:

```
* php
* composer
* docker
```

Build

```
./build
```

Run

```
docker run --name webpit -p 8080:8080 -v `pwd`/files:/app/files -v `pwd`/data:/app/data webpit
```


