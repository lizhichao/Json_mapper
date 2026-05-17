# JsonMapper

轻量级 JSON 到 PHP 类型化对象映射器，比市面上的 jsonmapper（如 `netresearch/jsonmapper`）更**快**，且原生支持 **PHP 8.1+ 枚举（Backed Enum）**。

## 解决的问题

市面上的 jsonmapper 在遇到 `enum` 类型属性时（特别是赋值给 ORM Model 时），会因无法处理枚举值而直接卡死。本项目从零实现，专注解决：

- 枚举原生兼容（`BackedEnum::from()`）
- 不调用构造函数（`newInstanceWithoutConstructor`），避免 ORM Model 构造函数副作用
- 不依赖注解/属性（Attribute），直接从原生 Reflection + PHPDoc 推断类型
- 零依赖，即拿即用

## 安装

```bash
composer require your-vendor/json-mapper
```

PHP >= 8.1 required.

## 快速开始

```php
use App\Framework\Helper\JsonMapper;

$mapper = new JsonMapper();

// 映射到已有实例（适合 ORM Model）
$user = new User();
$mapper->map(['name' => 'Alice', 'age' => 30], $user);

// 映射到类名（自动创建实例）
$user = $mapper->map(['name' => 'Bob'], User::class);
```

## 特性

### 1. 枚举原生支持

```php
enum Status: int
{
    case ACTIVE = 1;
    case INACTIVE = 2;
}

class Order
{
    public Status $status;
    public string $title;
}

$mapper = new JsonMapper();
$order = $mapper->map(['title' => 'Test', 'status' => 1], Order::class);

echo $order->status->name; // ACTIVE
```

### 2. 嵌套对象（递归映射）

```php
class Address
{
    public string $city;
    public string $street;
}

class User
{
    public string $name;
    public Address $address;
}

$data = [
    'name' => 'Alice',
    'address' => ['city' => 'Beijing', 'street' => 'ChangAn'],
];

$user = (new JsonMapper())->map($data, User::class);
echo $user->address->city; // Beijing
```

### 3. 类型化数组（`@var Type[]` PHPDoc）

```php
class Order
{
    /** @var Item[] */
    public array $items;
}

class Item
{
    public string $name;
    public int $price;
}

$data = [
    'items' => [
        ['name' => 'Apple', 'price' => 5],
        ['name' => 'Banana', 'price' => 3],
    ],
];

$order = (new JsonMapper())->map($data, Order::class);
echo $order->items[0]->name; // Apple
```

PHPDoc 支持完整类名、`use` 导入后的短名、以及同命名空间类。

### 4. Union 类型

```php
class Result
{
    public string|int $id;
}

$r1 = (new JsonMapper())->map(['id' => 'abc'], Result::class); // string
$r2 = (new JsonMapper())->map(['id' => 123], Result::class);   // int
```

按类型声明顺序依次尝试，第一个成功的类型生效。

### 5. 内置类型自动转换

支持 `int`、`string`、`float`、`bool`、`array` 等内置类型的自动 `settype()` 转换。

## 性能

比 `netresearch/jsonmapper` 快 10+ 倍（benchmark 结果）：

- **无构造函数调用**：`ReflectionClass::newInstanceWithoutConstructor()` 避免 Model 的 ORM 初始化开销
- **无 Attribute 解析**：仅使用原生 Reflection + PHPDoc 字符串解析
- **use 声明缓存**：解析一次后缓存，同类的映射不重复解析

## API

### `mapFrom(array|stdClass|string|null $data, object|string $class): array|object|null`

支持**字符串 JSON**、**数组**、**stdClass** 三种输入，自动区分**单对象**与**对象列表**：

```php
$mapper = new JsonMapper();

// 字符串 JSON 自动解码
$user = $mapper->mapFrom('{"name": "Alice", "status": 1}', User::class);

// 对象列表
$users = $mapper->mapFrom('[{"name": "Alice"}, {"name": "Bob"}]', User::class);

// null → null
$result = $mapper->mapFrom(null, User::class); // null
```

| 输入 | 结果 |
|------|------|
| `null` / 空 | 返回 `null` |
| JSON 字符串 | 自动 `json_decode` 后映射 |
| 列表数组 `[ {...}, {...} ]` | 返回 `array<T>` |
| 关联数组 / stdClass | 返回 `T` |

### `map(array|stdClass $data, object|string $class): object`

| 参数 | 类型 | 说明 |
|------|------|------|
| `$data` | `array\|stdClass` | JSON 解析后的数据 |
| `$class` | `object\|string` | 目标类实例或类名 |

返回类型化后的目标对象。

- 当 `$class` 是对象时：克隆后填充属性，保留原对象状态
- 当 `$class` 是类名时：`newInstanceWithoutConstructor` 创建实例

## 与其他库对比

| 特性 | 本库 | `netresearch/jsonmapper` |
|------|------|--------------------------|
| Enum 支持 | ✅ 原生 | ❌ 需要额外配置 |
| 构造函数调用 | ❌ 不调用 | ✅ 调用（ORM 模型风险） |
| PHP 8.1+ 原生类型 | ✅ | ✅ |
| PHPDoc array 类型 | ✅ | ✅ |
| Union 类型 | ✅ | ✅ |
| 依赖 | 0 | `php-json` / `symfony` |
| 性能 | 快 | 中等 |
