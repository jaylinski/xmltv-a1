# XMLTV A1

Creates a [XMLTV](http://www.xmltv.org) EPG via Magenta EPG API and (optionally) maps channel IDs to A1TV.

> Note: Sky channels are currently skipped in order to reduce EPG size

## Development

In `public/index.php`:

* Remove the if-block `if (!file_exists($generating) ...)` to bypass caching
* Enable debugging by setting `new Magenta($psr16Cache, $debug = true, true)`
* Remove the last 8 lines of code to avoid spamming your console

Execute `php public/index.php` to generate the EPG.

## Example nginx config

```nginx
server {
    listen       80;
    server_name  a1.epg.test;
    root         /srv/www/a1.epg.test/public;

    location / {
        index  index.php;
    }

    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass   php-epg-test:9000;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME /srv/www/public$fastcgi_script_name;
        fastcgi_read_timeout 900s;
        include        fastcgi_params;
    }
}
```
