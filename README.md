## Laravel API Documentation Generator

`php artisan api:gen --routePrefix=settings/api/*`

![image](http://img.shields.io/packagist/v/mpociot/laravel-apidoc-generator.svg?style=flat)
![image](http://img.shields.io/packagist/l/mpociot/laravel-apidoc-generator.svg?style=flat)
[![codecov.io](https://codecov.io/github/mpociot/laravel-apidoc-generator/coverage.svg?branch=master)](https://codecov.io/github/mpociot/laravel-apidoc-generator?branch=master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/mpociot/laravel-apidoc-generator/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/mpociot/laravel-apidoc-generator/?branch=master)
[![Build Status](https://travis-ci.org/mpociot/laravel-apidoc-generator.svg?branch=master)](https://travis-ci.org/mpociot/laravel-apidoc-generator)


### Install

Require this package with composer using the following command:

```bash
composer require mpociot/laravel-apidoc-generator
```
Go to your `config/app.php` and add the service provider:

```php
Mpociot\ApiDoc\ApiDocGeneratorServiceProvider::class
```

### Usage


```
php artisan api:generate 
    {--output=public/docs : The output path for the generated documentation}
    {--routePrefix= : The route prefix to use for generation - * can be used as a wildcard}
    {--routes=* : The route names to use for generation - if no routePrefix is provided}
    {--actAsUserId= : The user ID to use for API response calls}
```


### License

The Laravel API Documentation Generator is free software licensed under the MIT license.