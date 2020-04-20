<?php

namespace djidji;

use Yii;
use yii\base\BaseObject;
use yii\web\UrlRuleInterface;

class DefaultUrlRule extends BaseObject  implements UrlRuleInterface
{
    public $routesFile='@app/config/routes.php';

    /**
     * Initializes this rule by ensuring the path for routes file exists.
     */
    public function init()
    {
        $this->routesFile = Yii::getAlias($this->routesFile);
    }

    /**
     * {@inheritdoc}
     */
    public function createUrl($manager, $route, $urlArgs)
    {
        if ($manager->enableStrictParsing) {
            return false;
        }
        $args='';
        $cacheKey =$route.'&'.implode('$',array_keys($urlArgs));

        if (\file_exists($this->routesFile)) {
            $routes=require $this->routesFile;
        }
        if (!isset($routes[$cacheKey])) {
            if ($parts=Yii::$app->createController($route)) {
                $params=$this->validateActionId($parts[0],$parts[1],$urlArgs);
                if (is_array($params)) {
                    $routeConf=$routes[$cacheKey]=['route'=>$route,'params'=>array_keys($params)];

                    file_put_contents($this->routesFile, "<?php  \nreturn ".var_export($routes,true).";");

                }else {
                    return false;
                }
            }else {
                return false;
            }
        }else{
            $routeConf=$routes[$cacheKey];
        }

        foreach ($routeConf['params'] as $argName) {
            if (array_key_exists($argName, $urlArgs)) {
                $args .= '/'.$urlArgs[$argName];
                unset($urlArgs[$argName]);
            }
        }


        $route.=$args!==''?"/{$manager->routeParam}{$args}":'';
        if ($manager->suffix !== null) {
            $route .= $manager->suffix;
        }
        if (!empty($urlArgs) && ($urlArgs = http_build_query($urlArgs)) !== '') {
            $route .= '?' . $urlArgs;
        }

        return $route;
    }

    /**
     * {@inheritdoc}
     */
    public function parseRequest($manager, $request)
    {
        if ($manager->enableStrictParsing) {
            return false;
        }
        $suffix = (string) $manager->suffix;
        $pathInfo = $request->getPathInfo();
        $normalized = false;
        if ($manager->normalizer !== false) {
            $pathInfo = $manager->normalizer->normalizePathInfo($pathInfo, $suffix, $normalized);
        }
        if ($suffix !== '' && $pathInfo !== '') {
            $n = strlen($manager->suffix);
            if (substr_compare($pathInfo, $manager->suffix, -$n, $n) === 0) {
                $pathInfo = substr($pathInfo, 0, -$n);
                if ($pathInfo === '') {
                    // suffix alone is not allowed
                    return false;
                }
            } else {
                // suffix doesn't match
                return false;
            }
        }
        $pathInfo=trim($pathInfo,'/');
        if (strpos($pathInfo, "/{$manager->routeParam}/") !== false) {
            list($route,$urlArgs)=explode("/{$manager->routeParam}/",$pathInfo);
            $urlArgs=explode('/',$urlArgs);
        }else {
            $route=$pathInfo!==''?$pathInfo:Yii::$app->defaultRoute;
            $urlArgs=[];
        }
            
        $cacheKey =$route.'&'.count($urlArgs);

        if (\file_exists($this->routesFile)) {
            $routes=require $this->routesFile;
        }

        if (!isset($routes[$cacheKey])) {
            if ($parts=Yii::$app->createController($route)) {
                if (($params=$this->validateActionId($parts[0],$parts[1],$urlArgs,true))!==false) {
                    $routeKey=$route.'&'.implode('$',array_keys($params));
                    if (!isset($routes[$routeKey])) {
                        $routes[$routeKey]=['route'=>$route,'params'=>array_keys($params)];
                    }
                    $routes[$cacheKey]=$routeKey;
                    file_put_contents($this->routesFile, "<?php  \nreturn ".var_export($routes,true).";");

                }else {
                    return false;
                }
            }else {
                return false;
            }
        }else{
            $params=array_combine($routes[$routes[$cacheKey]]['params'], $urlArgs);

        }

        if ($normalized) {
            // pathInfo was changed by normalizer - we need also normalize route
            return $manager->normalizer->normalizeRoute([$route,$params]);
        }

        return [$route,$params];
    }


    /**
     * Verifie if the actionId and args have a corresponding Controller method and parameters
     *
     * @param string $actionId actionId to be validates
     * @param array $args passed arguments
     * @param bool $maxArgs verify that arguments should not be more than delared method parameters
     * @return array|bool index being method parameter name and value being passed argument or false if method not found
     */
    public function validateActionId($controller,$actionId,$args,$maxArgs=false)
    {
        $actionId=$actionId?:$controller->defaultAction;
        $actionMap= $controller->actions();
        if (isset($actionMap[$actionId])) {
            $actionClass=isset($actionMap[$actionId]['class'])?$actionMap[$actionId]['class']:$actionMap[$actionId];
            $method = new \ReflectionMethod($actionClass, 'run');
        }
        if (preg_match('/^(?:[a-z0-9_]+-)*[a-z0-9_]+$/', $actionId)) {
            $methodName = 'action' . str_replace(' ', '', ucwords(str_replace('-', ' ', $actionId)));
            if (method_exists($controller, $methodName)) {
                $method = new \ReflectionMethod($controller, $methodName);
            }
        }

        if (isset($method) && $method->isPublic()) {
            if ($method->getNumberOfRequiredParameters()<=count($args)) {
                if ($maxArgs && count($args)>$method->getNumberOfParameters()) {
                    return false;
                }
                return $this->routifyUrlParams($method,$args);

            }
        }

        return false;

    }

    /**
     * Map and sort url or route Arguments in the way defined by the controller method
     *
     * This is called by [[validateActionId()]] when method corresponding to actionId is found
     * @param \ReflectionMethod $method \ReflectionMethod instance of action method
     * @param array $urlArgs arguments to be passed
     * @return array index being method parameter name and value being passed argument
     */
    public function routifyUrlParams($method,$urlArgs)
    {
        $actionParams = [];
        $optionals=[];
        foreach ($method->getParameters()as $index => $param) {
            $name = $param->getName();
            if (isset($urlArgs[$index])) {
                $urlArgs[$name]=$urlArgs[$index];
                unset($urlArgs[$index]);
            }
            if (array_key_exists($name, $urlArgs)) {

                if ($param->isDefaultValueAvailable()) {
                    $actionParams+=$optionals;
                    $optionals=[];

                }
                $actionParams[$name] = $urlArgs[$name];
                unset($urlArgs[$name]);
            }elseif ($param->isDefaultValueAvailable()) {
                $optionals[$name]=$param->getDefaultValue();

            }
        }

        return $actionParams;

    }

}
