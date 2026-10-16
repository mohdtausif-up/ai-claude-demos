<?php

declare(strict_types=1);

namespace App\Core;

use ReflectionClass;
use ReflectionNamedType;

/** Minimal autowiring container: constructor parameters typed as classes are resolved recursively (singletons). */
final class Container
{
    /** @var array<string,object> */
    private static array $instances = [];

    public static function get(string $class): object
    {
        if (isset(self::$instances[$class])) {
            return self::$instances[$class];
        }
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        $args = [];
        foreach ($ctor?->getParameters() ?? [] as $p) {
            $t = $p->getType();
            if ($t instanceof ReflectionNamedType && !$t->isBuiltin()) {
                $args[] = self::get($t->getName());
            } elseif ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
            } else {
                throw new \LogicException("Cannot autowire \${$p->getName()} of $class");
            }
        }
        return self::$instances[$class] = $ref->newInstanceArgs($args);
    }

    public static function bind(string $class, object $instance): void
    {
        self::$instances[$class] = $instance;
    }

    public static function flush(): void
    {
        self::$instances = [];
    }
}
