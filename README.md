Bigcommerce php client
======================

Lightweight PHP client for the [Bigcommerce API](https://developer.bigcommerce.com/api/).

Requirements
------------
- PHP 4 or greater
- [cURL extention](http://php.net/manual/en/book.curl.php)
- Only compatible with [OAuth2 Bigcommerce Apps](https://developer.bigcommerce.com/apps/authentication)

Usage
------------
```php
// Retrieve access Token
$client = new BigcommerceClient($_GET['context'], CLIENT_ID, CLIENT_SECRET);
$access_token = $client->getAccessToken($_GET['code'], $_GET['scope'], REDIRECT_URI);

// API call examples
$client = new BigcommerceClient(CONTEXT, CLIENT_ID, CLIENT_SECRET, ACCESS_TOKEN);
// List Products
$products = $client->call('GET', '/v2/products');
// Create Product
$params = array("name"          => "Plain T-Shirt",
                "type"          => "physical",
                "description"   => "This timeless fashion staple will never go out of style!",
                "price"         => "29.99",
                "categories"    => array(18),
                "availability"  => "available",
                "weight"        => "0.5",
                "is_visible"    => true
                );
$product = $client->call('POST', '/v2/products', $params);
```
