# Laravel Scaffold

## Installation

You can install the package via composer:

```bash
composer require --dev charsen/laravel-scaffold
```

The package will register itself automatically. 

Optionally you can publish the package configuration using:

```bash
php artisan vendor:publish --provider=Charsen\\Scaffold\\ScaffoldProvider
```

This will publish a file called `scaffold.php` in your `config` folder.
In the config file, you can specify the dump server host that you want to listen on, in case you want to change the default value.

## Usage
### 1. Create Folders
```
php artisan scaffold:folders
```

### 2. Create Database Schema Generator
```
php artisan scaffold:db:schema `file_name`
```
add `-f` option to overwrite schema.

### 3. Fresh Database Storage Files
```
php artisan scaffold:db:fresh
```
add `-f` option to remove all the storage files before "fresh".

### 4. Database's url
```
http://{{url}}}/scaffold/dbs
```


## todo
- ScaffoldProvider 里 view 指定的 namesapce 未从指定地方获取
- Http/Views/* extends, include 里 view 指定的 namesapce 未从指定地方获取


## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email 780537@gmail.com instead of using the issue tracker.

## Credits

- [Lamtim](https://github.com/Lamtin)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
 
