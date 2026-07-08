<?php

declare(strict_types=1);

class JsonMapper
{
    public function __construct(public bool $assignDynamicProperties = false)
    {
    }

    /**
     * 从各种来源映射：自动 JSON 解码，自动判断单对象/对象列表
     *
     * @template T of object
     * @param array|stdClass|string|null $data JSON 字符串、数组、stdClass 或 null
     * @param T|class-string<T> $class 目标类名或实例
     * @return array<T>|T|null null 当 $data 为空时返回
     */
    public function mapFrom(array|stdClass|string|null $data, object|string $class): array|object|null
    {
        if (empty($data)) {
            return null;
        }
        if (is_string($data)) {
            $data = json_decode($data);
        }
        if (is_array($data) && array_is_list($data)) {
            $ret = [];
            foreach ($data as $val) {
                $ret[] = $this->map($val, $class);
            }
            return $ret;
        }
        return $this->map($data, $class);
    }

    /**
     * @template T
     * @param array|stdClass $data JSON 数据或已经实例化的对象
     * @param class-string<T>|object $class 要映射的目标类名或已经实例化的对象
     * @return T
     */
    public function map(array|stdClass $data, object|string $class): object
    {
        // 如果 $class 已经是对象，直接使用该对象
        if (is_object($class)) {
            $object = clone $class;
            $className = get_class($class);
            $refClass = new ReflectionClass($className);
        } else {
            $refClass = new ReflectionClass($class);
            $object = $refClass->newInstanceWithoutConstructor();
        }

        // 如果 $data 是 stdClass 或对象，转换为数组以便遍历
        $dataArray = is_object($data) ? (array)$data : $data;

        foreach ($dataArray as $key => $value) {
            // class 中不存在该属性 → 动态属性
            if (!$refClass->hasProperty($key)) {
                if ($this->assignDynamicProperties) {
                    $object->{$key} = $value;
                }
                continue;
            }

            $property = $refClass->getProperty($key);
            $type = $property->getType();

            $property->setValue(
                $object,
                $this->castValue($value, $type, $property)
            );
        }

        return $object;
    }

    private function castValue(mixed $value, ?ReflectionType $type, ?ReflectionProperty $property = null): mixed
    {
        if ($value === null || $type === null) {
            return $value;
        }

        // union type: ?Type | A|B
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $subType) {
                try {
                    return $this->castValue($value, $subType, $property);
                } catch (Throwable) {
                }
            }
            return null;
        }

        /** @var ReflectionNamedType $type */
        $typeName = $type->getName();

        if (is_array($value) && $typeName === 'array' && array_is_list($value)) {
            return $this->castArrayValue($value, $property);
        }
        // 内置类型
        if ($type->isBuiltin()) {
            if (is_object($value)) {
                if ($typeName === 'string' && method_exists($value, '__toString')) {
                    return $value->__toString();
                }
                return $value;
            }
            settype($value, $typeName);
            return $value;
        }

        // Enum (PHP 8.1+)
        if (enum_exists($typeName) && method_exists($typeName, 'from')) {
            return $typeName::from($value);
        }

        // 对象递归映射
        if (is_object($value)) {
            return $this->map($value, $typeName);
        }
        return $value;
    }

    /**
     * 处理 Address[] / array<Address>
     */
    private function castArrayValue(array $value, ?ReflectionProperty $property): array
    {
        if ($property === null) {
            return $value;
        }

        $itemClass = $this->resolveArrayItemClass($property);

        // 普通 array
        if ($itemClass === null) {
            return $value;
        }

        $result = [];
        foreach ($value as $item) {
            $result[] = (is_array($item) && array_is_list($item))
                ? $this->castArrayValue($item, $property)
                : $this->map($item, $itemClass);
        }

        return $result;
    }

    /**
     * 从 PHPDoc 解析 array 元素类型
     */
    private function resolveArrayItemClass(ReflectionProperty $property): ?string
    {
        $doc = $property->getDocComment();
        if (!$doc) {
            return null;
        }

        if (!preg_match('/@var\s+([\w\\\]+)(\[\]|<.*>)?/', $doc, $m)) {
            return null;
        }

        $rawType = trim($m[1], '\\');

        // 已是 FQCN
        if (str_contains($rawType, '\\') && class_exists($rawType)) {
            return $rawType;
        }

        $declaringClass = $property->getDeclaringClass();

        // 解析 use
        $imports = self::parseUseStatements($declaringClass);

        if (isset($imports[$rawType])) {
            return $imports[$rawType];
        }

        // 当前 namespace
        $nsClass = $declaringClass->getNamespaceName() . '\\' . $rawType;
        if (class_exists($nsClass)) {
            return $nsClass;
        }

        return null;
    }

    private function parseUseStatements(ReflectionClass $class): array
    {
        static $cache = [];

        $className = $class->getName();
        if (isset($cache[$className])) {
            return $cache[$className];
        }

        $file = $class->getFileName();
        if (!$file || !is_file($file)) {
            return [];
        }

        $code = file_get_contents($file);
        $tokens = token_get_all($code);

        $uses = [];
        $namespace = '';
        $collect = false;
        $alias = '';
        $full = '';
        $inAlias = false;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                match ($token[0]) {
                    T_NAMESPACE => $collect = 'ns',
                    T_USE => $collect = 'use',
                    T_STRING,
                    T_NAME_QUALIFIED => match ($collect) {
                        'ns' => $namespace .= $token[1],
                        'use' => $inAlias ? $alias .= $token[1] : $full .= $token[1],
                        default => null
                    },
                    T_AS => $inAlias = true,
                    default => null
                };
            } else {
                if ($collect === 'use' && ($token === ';' || $token === ',')) {
                    $parts = explode('\\', $full);
                    $short = $alias ?: end($parts);
                    $uses[$short] = $full;
                    $full = '';
                    $alias = '';
                    $inAlias = false;
                    if ($token === ';') {
                        $collect = false;
                    }
                }

                if ($collect === 'ns' && $token === ';') {
                    $collect = false;
                }
            }
        }

        return $cache[$className] = $uses;
    }
}
