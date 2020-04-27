UrlRule for default basic  logic in parsing and creating pretty Url.
======================================================================
Parsing and creating url with arguments pretified as well instead of in query string format. Route and argument segments of url are separated by the UrlManager->routeParam

Conventions
----------
1. Argument is prettified only if its name is declared as action method parameter otherwise it stay in query string format;

1. Arguments are orderer by the way corresponding action method parameters are declared;

1. Optional Argument nth binds to its corresponding action method parameter only if all arguments --nth are explicitly passed. --nth argments are inserted at their respective places in created url if were nor explicit;

1. Arguments less than required or more than action method parameters show 404


Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist djidji/yii2-default-url-rule "*"
```

or add

```
"djidji/yii2-default-url-rule": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Once the extension is installed, simply use it in your code by declaring in rules of Urlmanager :

```
'rules' => [
    // ...other url rules...
    [
        'class' => 'djidji\DefaultUrlRule',
        // complete file path to save routes.
        'routesFile' => '@app/config/routes.php'
    ],
]

```
Examples
--------

```
class PostController extends Controller
{
    public function actionIndex($category,$year=2015,$tag='')
    {

    }
    public function actionView($id)
    {

    }
}
```
### To create Url

- `Url::to(['post/index', 'year' => 2014, 'category' => 'php'])` creates  `/index.php/post/index/r/php/2014` ;

- `Url::to(['post/index', 'category' => 'php'])` creates `/index.php/post/index/r/php`;

- `Url::to(['post/index', 'category' => 'php','tag'=>'programming'])` creates `/index.php/post/index/r/php/2015/programming`. default value of parameter 'year' is inserted at its place;

- `Url::to(['post/index','year' => 2014, 'category' => 'php'])` result to `false` because the argument for required `$category` parameter is not passed;

- `Url::to(['post/view', 'id' => 100])` creates `/index.php/post/view/r/100` ;

- `Url::to(['post/view', 'id' => 100, 'source' => 'ad'])` creates `creates /index.php/post/view/r/100?source=ad`. Because "source" argument is not declared as actionView methoth, it is appended as a query parameter in the created URL.

### To parse Url

- `/index.php/post/index`  result to `false` because actionIndex has required firs parameter that need argments to be passed.

- `/index.php/post/index/r/php` is parsed to `['post/index', ['category' => 'php']]` ;

- `/index.php/post/index/r/php/2015/programming` is parsed to `['post/index', ['category' => 'php','tag'=>'programming','year'=>2015]]` ;

- `/index.php/post/index/r/php/programming` is parsed to `['post/index', ['category' => 'php','year'=>'programming']]` ;

- `/index.php/post/view/r/100?source=ad` is parsed to `['post/view', ['id' => 100]]`;

- `/index.php/post/view/r/100/ad?source=ad` result to `false` because actionView method expect one argment instead of two.

