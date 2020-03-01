# PHP Router
PHP router with advanced features and some experimental features such as `RouterFirewall`
that uses APCu to store firewall rules, block/allow IP addresses, block malicious looking requests and ratelimit users.

**Note:** Use `Router.php` as a reference only. Don't include it. Use the minified version instead or minify it yourself.

### Installation
- Create your `index.php` file which is going to handle all the requests
- Download `Router.min.php` and place it to your HTTP server's root directory
- Create a `.htaccess` file and make it redirect all requests to `index.php`

### Usage
In your `index.php` you could add something like this
```php
// create new router
include_once('Router.min.php');
$router = new Router();

// add routes
$router::routex('*', null, '/.*/', function($self, $values) {
    // this is called if any of the routes below don't match
    $self::serve($_SERVER['DOCUMENT_ROOT'].'/sites/-', $self, $values, true);
});

$router::routex('*', 'example.com', '/.*/', function($self, $values) {
    // accept any request coming to example.com and automatically serve content from
    // ../sites/example.com/
    // like a regular http server would do
    $self::serve($_SERVER['DOCUMENT_ROOT'].'/sites/example.com', $self, $values, true);
});

$router::routex('GET', 'dev.example.com', '/^\/(.*)\/(.*)$/', function($self, $values, $a, $b) {
    // this could be used to handle GET requests to 
    // https://dev.example.com/hello/world
    // $a = hello
    // $b = world
});

// handle routing errors
$router::error(null, function($self, $values){
    $self::status(404);
});

// accept the request and perform routing
$router::accept();
```

I have added comments and explained what most methods do in ``Router.php``
but  I will try to write better documentation here some day.
