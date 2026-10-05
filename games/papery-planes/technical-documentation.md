# Papery Planes 技术文档

**游戏名称**：Papery Planes（纸飞机）
**类型**：Unity WebGL 3D 游戏
**技术方案**：Unity 2019.3 构建 + PHP 分块合并 + IndexedDB 存档修改
**部署方式**：Apache + PHP 虚拟主机（InfinityFree 等）

---

## 目录

1. [项目概述](#1-项目概述)
2. [文件结构](#2-文件结构)
3. [Unity WebGL 构建产物解析](#3-unity-webgl-构建产物解析)
4. [分块部署方案](#4-分块部署方案)
5. [PHP 分块合并脚本详解](#5-php-分块合并脚本详解)
6. [.htaccess 重写规则](#6-htaccess-重写规则)
7. [无限金币实现原理](#7-无限金币实现原理)
8. [PlayerPrefs 二进制格式](#8-playerprefs-二进制格式)
9. [移动端兼容问题](#9-移动端兼容问题)
10. [部署指南](#10-部署指南)
11. [踩坑记录与故障排查](#11-踩坑记录与故障排查)

---

## 1. 项目概述

### 1.1 项目背景

Papery Planes 是一款 Unity 3D 纸飞机游戏，玩家操控纸飞机在各种场景中飞行，收集金币，解锁新飞机。

本项目的核心挑战：
1. **文件过大**：Unity WebGL 构建产物有 20MB+，超过 InfinityFree 的 10MB 单文件限制
2. **无限金币**：通过修改本地存档实现，不修改游戏本体
3. **移动端兼容**：Unity WebGL 在移动端有各种坑

### 1.2 技术选型

| 组件 | 选择 | 原因 |
|------|------|------|
| 游戏引擎 | Unity 2019.3.0f3 | 游戏原版就是 Unity 构建的 |
| 分块方案 | 服务器端 PHP 合并 | 对 Unity 透明，兼容性最好 |
| 存档修改 | IndexedDB 二进制补丁 | 不修改游戏代码，只改存档数据 |
| 服务器 | Apache + PHP | InfinityFree 免费主机环境 |
| 前端 | 原生 JS | 轻量，不引入额外依赖 |

### 1.3 功能列表

- ✅ 原版游戏完整运行
- ✅ 无限金币（999999）
- ✅ 全部飞机解锁（通过金币购买）
- ✅ 分块部署，突破 10MB 文件限制
- ✅ 支持 HTTP Range 请求（WASM 流式编译）
- ✅ 正确的 MIME 类型配置
- ✅ 浏览器缓存优化

---

## 2. 文件结构

```
papery-planes/
├── index.html                    # 游戏页面（含金币修改逻辑）
├── UnityLoader.2019.1.js         # Unity WebGL 加载器
├── technical-documentation.md    # 本文档
└── Build/
    ├── .htaccess                 # 重写规则（.unityweb → unityweb.php）
    ├── unityweb.php              # PHP 分块合并脚本
    ├── ppw.json                  # Unity 构建配置
    ├── ppw.data.unityweb.part0   # 游戏数据分块 1（~10MB）
    ├── ppw.data.unityweb.part1   # 游戏数据分块 2（~2MB）
    ├── ppw.wasm.code.unityweb.part0   # WASM 代码分块 1（~10MB）
    ├── ppw.wasm.code.unityweb.part1   # WASM 代码分块 2（~10MB）
    ├── ppw.wasm.code.unityweb.part2   # WASM 代码分块 3（~10MB）
    └── ppw.wasm.framework.unityweb    # JS 框架（512KB，不分块）
```

### 文件大小明细

| 文件 | 大小 | 分块数 | 说明 |
|------|------|--------|------|
| ppw.data.unityweb | ~12 MB | 2 | 游戏资源（模型、贴图、音效等） |
| ppw.wasm.code.unityweb | ~28 MB | 3 | C# 编译后的 WASM 代码 |
| ppw.wasm.framework.unityweb | 512 KB | 0 | Unity JS 框架（小，不分块） |
| UnityLoader.2019.1.js | ~120 KB | — | Unity 加载器 |
| ppw.json | < 1 KB | — | 构建配置 |

---

## 3. Unity WebGL 构建产物解析

### 3.1 Unity WebGL 构建流程

Unity 打包 WebGL 的过程：
1. C# 代码 → 编译成 DLL → 通过 IL2CPP 转成 C++ → 编译成 WASM
2. 资源文件（模型、贴图、音效）→ 打包成 data 文件
3. 生成 UnityLoader.js（加载器）和一个 JSON 配置文件

### 3.2 四个核心文件

| 文件 | 作用 | 对应本项目 |
|------|------|-----------|
| `*.data.unityweb` | 游戏数据（资源） | ppw.data.unityweb |
| `*.wasm.code.unityweb` | WASM 代码 | ppw.wasm.code.unityweb |
| `*.wasm.framework.unityweb` | JS 框架（胶水代码） | ppw.wasm.framework.unityweb |
| UnityLoader.js | 加载器 | UnityLoader.2019.1.js |

### 3.3 unityweb 文件格式

`.unityweb` 文件本质上是 gzip 压缩的二进制文件：
- 文件名加 `.unityweb` 后缀
- Content-Encoding: gzip
- Content-Type 根据类型不同而不同

**注意**：不是所有 Unity 版本都用 gzip。早期版本可能是未压缩的，新版本 Unity 支持 Brotli 压缩。

### 3.4 加载流程

```
浏览器加载 UnityLoader.js
    ↓
UnityLoader 读取 ppw.json 配置
    ↓
UnityLoader 开始加载三个 unityweb 文件：
  ├─ data.unityweb（游戏资源）
  ├─ wasm.code.unityweb（WASM 代码）
  └─ wasm.framework.unityweb（JS 框架）
    ↓
WASM 流式编译（如果 MIME 类型正确）
    ↓
JS 框架初始化 WASM 运行时
    ↓
游戏启动
```

### 3.5 WASM 流式编译

`WebAssembly.instantiateStreaming()` 可以在下载 WASM 的同时编译，比"先下载完再编译"快很多。

**前提条件**：响应的 Content-Type 必须是 `application/wasm`。

如果 MIME 类型不对，浏览器会回退到 ArrayBuffer 模式：
- 速度更慢（需要完整下载后再编译）
- 内存占用更高（整个文件在内存中复制一份）
- 大文件可能因内存不足导致卡死

**这就是为什么 MIME 类型配置非常重要。**

---

## 4. 分块部署方案

### 4.1 问题背景

InfinityFree 等免费虚拟主机有单文件 10MB 上传限制，而 Unity WebGL 的 WASM 文件通常 20MB+，直接传不上去。

### 4.2 方案选型对比

| 方案 | 原理 | 优点 | 缺点 |
|------|------|------|------|
| **A. 前端 JS 合并** | 前端 fetch 分块，合并成 Blob URL | 不需要 PHP | DataView 偏移错误，兼容性差 |
| **B. Service Worker** | SW 拦截请求，合并分块 | 对 Unity 透明 | 兼容性问题，需要 HTTPS，首次加载不生效 |
| **C. 服务器端 PHP 合并** | .htaccess 重写 + PHP 合并输出 | 对 Unity 完全透明，兼容性最好 | 需要 PHP 环境 |

**最终选择方案 C（服务器端 PHP 合并）**，原因：
1. 对 Unity 引擎完全透明，就像访问完整文件一样
2. 兼容性最好（Unity 内部的加载逻辑完全不用改）
3. 支持 HTTP Range 请求（WASM 流式编译必需）
4. 支持正确的 MIME 类型

### 4.3 方案原理

```
浏览器请求 ppw.wasm.code.unityweb
    ↓
.htaccess RewriteRule 拦截
    ↓
转发给 unityweb.php?f=ppw.wasm.code.unityweb
    ↓
PHP 脚本读取 .part0, .part1, .part2
    ↓
PHP 合并后输出（就像一个完整文件）
    ↓
浏览器收到完整文件，不知道是合并的
```

### 4.4 分块原则

- 每块不超过 10MB（InfinityFree 的限制）
- 小文件不分块（< 1MB 直接传）
- 分块数量尽量少（越少，合并开销越小）

本项目的分块：
- `ppw.data.unityweb` → 2 块（10MB + 2MB）
- `ppw.wasm.code.unityweb` → 3 块（约 9.3MB × 3）
- `ppw.wasm.framework.unityweb` → 不分块（512KB）

### 4.5 分块命名约定

```
原文件：ppw.data.unityweb
分块后：ppw.data.unityweb.part0  （第1块）
        ppw.data.unityweb.part1  （第2块）
        ...
```

按顺序拼接，`part0 + part1 + ... = 原文件`。

### 4.6 如何手动分块

**Linux/Mac：**
```bash
split -b 10m -d ppw.wasm.code.unityweb ppw.wasm.code.unityweb.part
```

**Windows PowerShell：**
```powershell
$file = [IO.File]::ReadAllBytes("ppw.wasm.code.unityweb")
$chunkSize = 10 * 1024 * 1024
$index = 0
for ($i = 0; $i -lt $file.Length; $i += $chunkSize) {
    $chunk = $file[$i..([Math]::Min($i+$chunkSize-1, $file.Length-1))]
    [IO.File]::WriteAllBytes("ppw.wasm.code.unityweb.part$index", $chunk)
    $index++
}
```

---

## 5. PHP 分块合并脚本详解

### 5.1 核心功能

`unityweb.php` 实现了：
- ✅ 白名单机制（防止路径遍历攻击）
- ✅ 正确的 MIME 类型（WASM 流式编译必需）
- ✅ HTTP Range 断点续传
- ✅ 30 天缓存头
- ✅ 小文件直通（framework 不分块）

### 5.2 白名单机制

```php
$ALLOWED_FILES = [
    'ppw.data.unityweb'           => 2,
    'ppw.wasm.code.unityweb'      => 3,
    'ppw.wasm.framework.unityweb' => 0,  // 0 = 不分块
];
```

**为什么需要白名单？**
如果直接用 `$_GET['f']` 去读文件，攻击者可以构造 `?f=../../etc/passwd` 进行路径遍历攻击。

白名单只允许访问列表中的文件，从根本上杜绝这个漏洞。

### 5.3 MIME 类型判断

```php
function get_mime_type($filename) {
    if (strpos($filename, 'wasm.code') !== false) {
        return 'application/wasm';          // WASM 代码 → 流式编译
    } elseif (strpos($filename, 'wasm.framework') !== false) {
        return 'application/javascript';     // JS 框架
    } else {
        return 'application/octet-stream';   // 数据文件
    }
}
```

`wasm.code` 文件必须返回 `application/wasm`，否则浏览器不会进行流式编译。

### 5.4 小文件直通

不分块的小文件（framework）直接用 `readfile()` 输出：

```php
if ($part_count === 0) {
    $filepath = __DIR__ . '/' . $f;
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);  // PHP 输出文件最高效的方式
    exit;
}
```

`readfile()` 直接走内核缓冲区，性能最好。

### 5.5 HTTP Range 请求处理

Unity 加载 WASM 时可能先用 Range 请求探测文件大小，所以必须支持 Range。

**Range 头格式：**
```
Range: bytes=1024-2047    // 请求 1024-2047 字节
Range: bytes=1024-        // 请求从 1024 字节到末尾
```

**响应状态码：**
- 完整文件 → `200 OK`
- 部分内容 → `206 Partial Content`
- 范围越界 → `416 Range Not Satisfiable`

### 5.6 分块合并算法

核心思路：把所有分块想象成首尾相接的"虚拟大文件"，从虚拟文件的 offset 位置读取 length 字节。

```
虚拟文件（概念上）：
[  part0  ][  part1  ][  part2  ]
0        10M       20M       28M

请求 Range: bytes=15M-25M
         ↓
part0：完全在起点之前 → 跳过
part1：从 5M 位置读 5M → 输出
part2：从 0 位置读 5M → 输出（读够了就停）
```

算法时间复杂度 O(n)，n = 分块数量，通常只有 2-3 块，非常快。

### 5.7 性能考虑

**PHP 合并会不会很慢？**
- 分块数量少（2-3 块），合并开销可以忽略
- `fread()` + `echo` 直接输出到响应流，不占内存
- 浏览器缓存（30天），大多数请求是 304 Not Modified
- 只有首次加载会走 PHP 合并

**内存占用：**
- 不会把整个文件读进内存
- 每次只读一个分块的一部分（最多 10MB）
- 输出后立即释放

---

## 6. .htaccess 重写规则

### 6.1 Build 目录的 .htaccess

```apache
RewriteEngine On
RewriteCond %{REQUEST_URI} \.unityweb$
RewriteRule ^(.+\.unityweb)$ unityweb.php?f=$1 [L,QSA]
```

**逐行解释：**
- `RewriteEngine On`：开启重写引擎
- `RewriteCond %{REQUEST_URI} \.unityweb$`：只重写 .unityweb 结尾的请求
- `RewriteRule ^(.+\.unityweb)$ unityweb.php?f=$1 [L,QSA]`：
  - 匹配 .unityweb 文件名
  - 转发到 unityweb.php，文件名作为 f 参数
  - `[L]`：Last，这是最后一条规则，匹配后不再往下走
  - `[QSA]`：Query String Append，保留原 URL 的查询参数（如 ?v=xxx）

### 6.2 为什么用重写而不是直接访问 PHP

**直接访问：**
```
/games/papery-planes/Build/unityweb.php?f=ppw.data.unityweb
```

**重写后：**
```
/games/papery-planes/Build/ppw.data.unityweb
```

好处：
1. **对 Unity 透明**：Unity 配置里就写正常的文件名，不需要改
2. **URL 更干净**：看起来就是正常的静态文件
3. **缓存友好**：URL 和正常文件一样，CDN/浏览器缓存正常工作

### 6.3 根目录 .htaccess

根目录的 .htaccess 配置了：
- MIME 类型（.wasm / .unityweb / .partN / .swf）
- GZIP 压缩（只压缩文本类，二进制不压缩）
- 浏览器缓存（静态资源 30 天，HTML 不缓存）
- 安全头（X-Content-Type-Options: nosniff）
- 禁止访问 .htaccess 等敏感文件

### 6.4 InfinityFree 的坑

**不要用这些指令**（InfinityFree 共享主机会报 500 错误）：
- ❌ `ServerSignature Off`
- ❌ `Options -Indexes -MultiViews`（部分可用，部分不行）
- ❌ `ErrorDocument 404 ...`（路径可能不对）
- ❌ 不在 `<IfModule>` 里的 `SetEnvIf`

**安全的写法：**
- 所有可选模块都包在 `<IfModule>` 里
- 只使用最基础的指令
- 不确定的就不要加

---

## 7. 无限金币实现原理

### 7.1 方案选型对比

| 方案 | 原理 | 能行吗 | 为什么不用 |
|------|------|--------|-----------|
| 内存扫描修改 | 扫描 WASM 内存，找到金币地址改值 | ❌ 容易崩溃 | WASM 内存越界会直接终止游戏 |
| SendMessage | 调用 Unity 的 C# 方法 | ❌ 不行 | Unity 2019 方法名被混淆，找不到 |
| 修改游戏代码 | 反编译 DLL，改逻辑重编 | ⚠️ 可行 | 太复杂，容易出问题 |
| **修改存档** | 改 IndexedDB 里的 PlayerPrefs | ✅ 最可靠 | 不改游戏，只改数据，风险最小 |

**最终选择：修改 IndexedDB 存档**。

### 7.2 Unity WebGL 的存档机制

Unity WebGL 的 PlayerPrefs 存在哪里？

**答案：IndexedDB，数据库名 `/idbfs`，表名 `FILE_DATA`。**

```
IndexedDB
  └─ /idbfs（数据库）
      └─ FILE_DATA（对象仓库）
          ├─ key: "/idbfs/Users/DefaultUser/AppData/.../playerprefs.dat"
          │   value: { contents: Uint8Array, timestamp: Date }
          └─ ... 其他文件
```

Unity 启动时：
1. 从 IndexedDB 读取 PlayerPrefs 文件
2. 解析后加载到内存
3. 之后游戏只用内存的副本

Unity 保存时：
1. 把内存中的 PlayerPrefs 序列化
2. 写回 IndexedDB

### 7.3 为什么必须启动前修改

因为 Unity 启动时把存档读到内存里，之后只用内存的副本。

```
启动前改数据库 → Unity 读取 → 内存里就是 999999 → 玩家看到满金币 ✅

启动后改数据库 → 内存里还是原来的值 → 玩家看不到变化 ❌
（要下次启动才生效）
```

### 7.4 实现策略

**两阶段策略：**

**阶段 1：启动前预修改**
- 用 `indexedDB.databases()` 检查数据库是否存在（无副作用）
- 存在 → 先改金币，再启动 Unity → 玩家看到 999999
- 不存在 → 直接启动 → Unity 自己创建全新的，不会崩

**为什么不直接 open 数据库？**
- 如果数据库不存在，`indexedDB.open()` 会创建一个空数据库
- 空数据库的结构和 Unity 期望的不一样
- Unity 启动后读不到正确格式的数据 → 崩溃（"invalid handle for stdin"）

**阶段 2：运行时定时补充**
- 每 15 秒检查一次数据库
- 如果金币低于 990000，就改成 999999
- 防止玩家花金币后被游戏保存覆盖
- 运行时改的是数据库，内存里的值不变，**但下次启动会生效**

### 7.5 为什么不能劫持 IndexedDB

最初尝试过劫持 `IDBObjectStore.prototype.put`，但会导致 Unity 崩溃：

**原因：**
- Unity 的文件系统（IDBFS）对数据格式要求非常严格
- 劫持后写入的数据类型可能不一致（ArrayBuffer vs Uint8Array）
- Unity 下次读取时解析失败 → stdin 断言失败 → 崩溃

**结论：** 不要碰 Unity 的 IndexedDB 写入流程，直接在外面改数据。

---

## 8. PlayerPrefs 二进制格式

### 8.1 整体结构

PlayerPrefs 的数据是一个二进制文件，存储所有键值对。

每个键值对的格式：
```
┌────────┬────────────┬──────┬──────────┐
│ 键名长度 │  键名字符串  │ 类型 │  值数据   │
│ (1字节) │  (N字节)    │(1字节)│ (M字节)  │
└────────┴────────────┴──────┴──────────┘
```

### 8.2 类型标记

| 类型标记 | 类型 | 值长度 |
|---------|------|--------|
| 0x01 | 未知 | — |
| 0xFE | int | 4 字节（小端序） |
| 0xFD | float | 4 字节 |
| 0xFC | string | 变长（长度前缀 + 字符串） |

### 8.3 金币字段的字节模式

"Coins" 字段（int 类型）的二进制模式：

```
位置：  i    i+1  i+2  i+3  i+4  i+5  i+6  i+7  i+8  i+9  i+10
值：    05   43   6F   69   6E   73   FE   ??   ??   ??   ??
       │    └──────────────┘   │    └──────────────┘
       │         "Coins"       │      金币值（4字节小端）
       键名长度 = 5           int 类型标记
```

对应的十六进制序列：
```
05 43 6F 69 6E 73 FE
```

### 8.4 999999 的小端序表示

```
999999 = 0x000F423F

大端序：00 0F 42 3F
小端序：3F 42 0F 00
```

Unity 用小端序存储整数，所以金币值的 4 个字节是 `3F 42 0F 00`。

### 8.5 搜索算法

```js
for (var i = 0; i < data.length - 10; i++) {
    if (data[i] === 5 &&           // 键名长度 = 5
        data[i+1] === 67 &&        // 'C'
        data[i+2] === 111 &&       // 'o'
        data[i+3] === 105 &&       // 'i'
        data[i+4] === 110 &&       // 'n'
        data[i+5] === 115 &&       // 's'
        data[i+6] === 0xFE) {      // int 类型标记
        
        // 找到了！i+7 开始是 4 字节的金币值
        var vi = i + 7;
        var cur = data[vi] | (data[vi+1]<<8) | 
                  (data[vi+2]<<16) | (data[vi+3]<<24);
        
        if (cur < 990000) {
            // 改成 999999
            data[vi]   = 0x3F;
            data[vi+1] = 0x42;
            data[vi+2] = 0x0F;
            data[vi+3] = 0x00;
            changed = true;
        }
        break;
    }
}
```

### 8.6 为什么阈值是 990000

```js
if (cur < 990000) { ... }
```

**原因：**
- 金币已经是 999999 时，不需要再改
- 设置阈值而不是精确判断，防止因为某些原因金币变成 999998、999997 等
- 990000 足够高，玩家正常玩不可能达到这个数
- 减少不必要的写入操作

### 8.7 类型保持

```js
var data = new Uint8Array(obj.contents);
var isAB = obj.contents instanceof ArrayBuffer;
// ... 修改 data ...
if (changed) {
    obj.contents = isAB ? data.buffer : data;  // 保持原始类型
    store.put(obj, pk);
}
```

**为什么要保持类型？**
- Unity 写入时可能是 Uint8Array，也可能是 ArrayBuffer
- 如果改完存回去的类型不一样，Unity 下次读取时可能解析失败
- 会导致 "invalid handle for stdin" 崩溃

**经验：** 修改存档数据时，**严格保持原始数据类型**，包括 ArrayBuffer 和 Uint8Array 的区别。

---

## 9. 移动端兼容问题

### 9.1 现象

手机浏览器切换到"电脑版本"（桌面模式）后：
- 游戏能加载
- 有声音
- 但画面是黑的（canvas 不显示内容）

### 9.2 原因分析

有声音没画面 = 游戏逻辑在跑，但 WebGL 渲染有问题。

可能的原因：
1. **WebGL 2.0 兼容性**：手机 GPU 对 WebGL 2.0 支持不如桌面
2. **DPR 太高**：手机 DPR 3x，实际渲染分辨率太大，显存不够
3. **内存不足**：移动端浏览器内存限制更严格，WASM 占太多内存导致渲染进程被杀
4. **Canvas 尺寸问题**：CSS 尺寸和实际画布尺寸不匹配

### 9.3 已实施的优化

**1. 移动端优先 WebGL 1.0**
```js
if (isMobile) {
    unityParams.Module.preferredWebGLVersion = 1;
}
```

WebGL 1.0 兼容性更好，虽然功能少一点，但 Unity 2019 的游戏够用。

**2. 移动端限制内存**
```js
TOTAL_MEMORY: isMobile ? 256 * 1024 * 1024 : 512 * 1024 * 1024
```

移动端分配 256MB 内存，桌面端 512MB。减少 OOM（内存不足）导致的崩溃。

**3. Canvas 尺寸修正**
```css
#gameContainer canvas {
    width: 100% !important;
    height: 100% !important;
    display: block;
    image-rendering: auto;
}
```

确保 canvas 填满容器，image-rendering 用默认值。

### 9.4 局限性

即使做了以上优化，**Unity WebGL 在移动端的体验仍然不如桌面端**：
- 加载慢（WASM 编译需要时间）
- 内存占用高
- 操作不便（Unity 游戏大多是为键鼠设计的）
- 部分低端手机直接不支持

**建议：** Unity WebGL 游戏主要面向桌面端，移动端能玩就玩，不能玩也正常。

---

## 10. 部署指南

### 10.1 部署环境要求

- Apache 服务器（支持 .htaccess + mod_rewrite）
- PHP 5.6+（unityweb.php 需要）
- 支持 IndexedDB 的浏览器（所有现代浏览器）

### 10.2 上传文件清单

按目录结构上传以下文件：

```
papery-planes/
├── index.html
├── UnityLoader.2019.1.js
└── Build/
    ├── .htaccess
    ├── unityweb.php
    ├── ppw.json
    ├── ppw.data.unityweb.part0
    ├── ppw.data.unityweb.part1
    ├── ppw.wasm.code.unityweb.part0
    ├── ppw.wasm.code.unityweb.part1
    ├── ppw.wasm.code.unityweb.part2
    └── ppw.wasm.framework.unityweb
```

**注意：** 只有 `.partN` 文件，**没有**完整的 `.unityweb` 文件（因为太大传不上来）。

### 10.3 .htaccess 上传

两个 .htaccess 文件都要上传：
1. 网站根目录的 `.htaccess`（MIME 类型、缓存、压缩）
2. `Build/` 目录的 `.htaccess`（重写规则）

如果根目录已经有 `.htaccess`，把内容合并进去，不要覆盖。

### 10.4 验证部署

上传完成后，按以下步骤验证：

**步骤 1：检查 .htaccess 是否生效**
- 访问网站首页
- 如果报 500 错误 → .htaccess 语法有问题
- 解决方法：注释掉可疑的行，逐行排查

**步骤 2：检查 unityweb 是否正常**
- 直接访问 `Build/ppw.wasm.framework.unityweb`
- 应该能下载（或显示 JS 代码）
- 如果 404 → 文件没传好
- 如果 500 → PHP 或重写有问题

**步骤 3：检查游戏能否加载**
- 访问游戏页面
- 看加载进度条能不能走到 100%
- 如果卡在 0% → MIME 类型或 gzip 有问题
- 如果报 "DataView" 错误 → 分块合并有问题

**步骤 4：检查金币**
- 进入游戏，看金币是不是 999999
- 如果是 0 → 清一下 IndexedDB 再刷新
- 清 DB 命令：`indexedDB.deleteDatabase('/idbfs'); location.reload();`

### 10.5 更新游戏

如果游戏有新版本，需要：
1. 重新分块新的 unityweb 文件
2. 上传新的 .partN 文件
3. 更新 ppw.json（如果有变化）
4. 更新 index.html（如果有变化）
5. 玩家需要清缓存才能拿到新版本

建议：在 URL 里加版本参数（`?v=20261005`），强制浏览器重新加载。

---

## 11. 踩坑记录与故障排查

### 11.1 加载一直 0%

**错误：** 进度条卡在 0%，不动。

**原因：** `.unityweb` 文件被服务器当成 gzip 编码了，浏览器解压失败。

**怎么判断：** F12 → Network → 看 .unityweb 文件的 Response Headers，如果有 `Content-Encoding: gzip` 就有问题。

**解决方法：** 在 .htaccess 中添加：
```apache
SetEnvIfNoCase Request_URI "\.unityweb$" no-gzip dont-vary
```

### 11.2 Offset is outside the bounds of the DataView

**错误：** `Uncaught RangeError: Offset is outside the bounds of the DataView`

**原因：** 前端 JS 合并分块的方案有兼容性问题，合并后的数据格式不对。

**解决方法：** 改用服务器端 PHP 合并（本项目的方案），对 Unity 完全透明。

### 11.3 invalid handle for stdin (1)

**错误：** `Uncaught abort("Assertion failed: invalid handle for stdin (1)")`

**原因：** PlayerPrefs 数据格式损坏，Unity 读取时解析失败。

**常见触发场景：**
- 手动往 IndexedDB 里写数据，类型不对（ArrayBuffer vs Uint8Array）
- 劫持了 IDBObjectStore.put，改变了写入数据的格式
- 数据库结构不对（自己 open 了空数据库）

**解决方法：**
1. 清掉 IndexedDB：`indexedDB.deleteDatabase('/idbfs')`
2. 不要劫持 IndexedDB 的写入
3. 修改存档时保持原始数据类型
4. 数据库不存在时不要主动创建

### 11.4 cachedDecompressedFileSizes 报错

**错误：** `Cannot read properties of undefined (reading 'cachedDecompressedFileSizes')`

**原因：** UnityLoader 内部的一个非致命错误，配置加载过程中的提前访问。

**影响：** 不影响游戏运行，游戏能正常玩。

**解决方法：** 可以忽略。非要消除的话，在 ppw.json 里加 `"cachedDecompressedFileSizes": {}`。

### 11.5 .htaccess 导致 500 错误

**错误：** 网站显示 500 Internal Server Error

**原因：** .htaccess 里有 InfinityFree 不支持的指令。

**常见的问题指令：**
- `ServerSignature Off`
- `Options -MultiViews`
- 不在 `<IfModule>` 里的模块指令

**解决方法：**
1. 所有可选模块都包进 `<IfModule>`
2. 只使用最基础的指令
3. 不确定的就删掉
4. 逐行注释排查

### 11.6 金币不生效

**现象：** 进入游戏金币还是 0。

**排查步骤：**
1. 是不是第一次玩？（数据库还不存在 → 金币是 0 正常）
2. 玩一会儿让游戏保存一次，刷新看看
3. 按 F12 → Application → IndexedDB → 看有没有 PlayerPrefs 文件
4. 如果有但金币不是 999999 → 检查 patchCoins 函数有没有执行
5. 控制台有没有报错？

**首次启动的行为：**
- 数据库不存在 → 不修改 → Unity 创建新存档 → 金币 0
- 玩一会儿，游戏保存 → 数据库有了
- 下次刷新 → 检测到数据库 → 修改金币 → 进入游戏就是 999999

这是故意的设计——为了不破坏数据库结构，数据库不存在时不主动创建。

### 11.7 手机上有声音没画面

参见第 9 章「移动端兼容问题」。

核心原因：Unity WebGL 移动端兼容性问题，WebGL 2.0 或内存导致渲染失败。

### 11.8 WASM 流式编译不生效

**现象：** WASM 加载慢，内存占用高。

**检查：** F12 → Network → 看 .wasm.code.unityweb 的 Response Headers，Content-Type 是不是 `application/wasm`。

**如果不是：** 检查 .htaccess 的 MIME 类型配置，以及 unityweb.php 中的 get_mime_type 函数。

---

## 12. Unity WebGL 构建原理深入

### 12.1 IL2CPP 编译流程

Unity WebGL 构建不是直接把 C# 编译成 WASM，中间有好几步：

```
C# 源码
   ↓
Mono 编译器 → CIL（通用中间语言，.dll 文件）
   ↓
IL2CPP（C++ 转换器）→ 把 CIL 转成 C++ 代码
   ↓
Emscripten（基于 LLVM）→ 把 C++ 编译成 WASM + JS 胶水代码
   ↓
最终产物：wasm.code.unityweb + wasm.framework.unityweb
```

**为什么用 IL2CPP 而不是直接把 Mono 编译成 WASM？**
- IL2CPP 性能更好（C++ 优化比 Mono JIT 快）
- 代码体积更小（可以 strip 掉没用的代码）
- Mono VM 太大，编译成 WASM 体积不可接受

### 12.2 三个 unityweb 文件的具体作用

#### ppw.wasm.code.unityweb（WASM 代码）
- 内容：游戏逻辑 + Unity 引擎核心 + .NET 运行时，全部编译成 WASM
- 大小：通常最大（本项目 28MB）
- 压缩：gzip 或 brotli
- 加载时：浏览器流式编译成机器码执行
- 特点：只读，执行时不修改

#### ppw.data.unityweb（游戏数据）
- 内容：所有资源文件（3D 模型、贴图、材质、音效、场景配置、Shader 等）
- 大小：第二大（本项目 12MB）
- 格式：Unity 自定义的资源包格式
- 加载时：Unity 引擎读取并解析，加载到内存
- 特点：大部分是只读的，但运行时可能修改部分数据

#### ppw.wasm.framework.unityweb（JS 框架）
- 内容：Unity JS 胶水代码 + Emscripten 运行时
- 大小：最小（512KB）
- 作用：
  - 桥接 JS 和 WASM
  - 管理 WASM 内存
  - 文件系统模拟（IDBFS）
  - 输入事件处理
  - 音频输出
  - 等等
- 特点：纯 JS，可读但混淆过

### 12.3 UnityLoader.js 的作用

UnityLoader.js 是 Unity 官方的 WebGL 加载器：
- 读取 JSON 配置文件
- 创建 canvas 元素
- 加载三个 unityweb 文件
- 初始化 WASM 运行时
- 管理加载进度
- 处理错误

### 12.4 ppw.json 配置详解

```json
{
    "companyName": "Akos Makovics",
    "productName": "Papery Planes",
    "productVersion": "1.0",
    "dataUrl": "ppw.data.unityweb?v=24012020",
    "wasmCodeUrl": "ppw.wasm.code.unityweb?v=24012020",
    "wasmFrameworkUrl": "ppw.wasm.framework.unityweb?v=24012020",
    "graphicsAPI": ["WebGL 2.0", "WebGL 1.0"],
    "webglContextAttributes": {"preserveDrawingBuffer": false},
    "splashScreenStyle": "Dark",
    "backgroundColor": "#231F20",
    "developmentBuild": false,
    "multithreading": false,
    "unityVersion": "2019.3.0f3",
    "cachedDecompressedFileSizes": {}
}
```

**关键字段：**

| 字段 | 说明 |
|------|------|
| `dataUrl` | 数据文件 URL |
| `wasmCodeUrl` | WASM 代码文件 URL |
| `wasmFrameworkUrl` | JS 框架文件 URL |
| `graphicsAPI` | 优先尝试的 WebGL 版本列表，按顺序尝试 |
| `preserveDrawingBuffer` | 是否保留绘图缓冲区（截图需要，但影响性能） |
| `multithreading` | 是否多线程（需要 SharedArrayBuffer，且有 COOP/COEP 限制） |
| `unityVersion` | Unity 版本号 |
| `cachedDecompressedFileSizes` | 解压后文件大小缓存（空对象也没事，Unity 会自己算） |

**版本参数 `?v=24012020` 的作用：**
- 防止浏览器缓存旧版本
- 修改了文件后，改一下版本号就能强制刷新
- 比清缓存方便得多

### 12.5 WebGL 2.0 vs 1.0

Unity 2019 支持两种 WebGL 版本：

| 特性 | WebGL 2.0 | WebGL 1.0 |
|------|-----------|-----------|
| 发布年份 | 2017 | 2011 |
| 3D 纹理 | ✅ | ❌ |
| 多重采样 | ✅ | ❌ |
| 实例化渲染 | ✅ | 需要扩展 |
| 着色器版本 | GLSL ES 3.0 | GLSL ES 1.0 |
| 桌面兼容性 | 几乎 100% | 100% |
| 移动端兼容性 | 约 80% | 约 95%+ |
| 性能 | 更好 | 稍差 |

Unity 的 `graphicsAPI` 配置是按顺序尝试的，第一个能用就用哪个。

---

## 13. 分块方案探索历程

### 13.1 方案 A：前端 JS 合并（失败）

**思路：**
- 前端用 fetch 或 XHR 加载所有 .part 文件
- 在 JS 里合并成一个 Uint8Array
- 创建 Blob URL 传给 Unity

**为什么失败：**
1. **DataView 偏移错误**：Unity 内部用 DataView 读取数据，Blob URL 的数据格式和 Unity 期望的不完全一致
2. **gzip 解压问题**：.unityweb 是 gzip 压缩的，前端合并后解压逻辑不对
3. **Range 请求不支持**：WASM 流式编译需要 Range 请求，Blob URL 不支持
4. **内存占用高**：整个文件在内存里复制好几份

**结论：** 前端合并看似简单，但坑太多，兼容性差。

### 13.2 方案 B：Service Worker 拦截（失败）

**思路：**
- 注册一个 Service Worker
- SW 拦截 .unityweb 请求
- 加载分块，合并后返回 Response

**为什么失败：**
1. **首次加载不生效**：Service Worker 需要先注册并激活，首次访问时还没激活
2. **HTTPS 限制**：SW 需要 HTTPS 环境（localhost 除外）
3. **更新麻烦**：SW 更新有延迟，调试困难
4. **兼容性**：部分浏览器对 SW 的支持不完善
5. **实现复杂**：需要处理 Range 请求、缓存策略等

**结论：** SW 方案理论上可行，但工程化问题太多，不适合快速部署。

### 13.3 方案 C：服务器端 PHP 合并（成功）

**思路：**
- .htaccess 把 .unityweb 请求重写到 PHP 脚本
- PHP 读取分块文件，合并后输出
- 对 Unity 完全透明

**为什么成功：**
1. **完全透明**：Unity 以为在访问正常文件，兼容性 100%
2. **支持 Range**：PHP 可以处理 Range 请求，WASM 流式编译正常
3. **正确的 MIME**：PHP 可以设置正确的 Content-Type
4. **缓存友好**：HTTP 缓存头正常工作
5. **实现简单**：PHP 代码只有 200 多行

**唯一的前提：** 服务器需要支持 PHP + Apache mod_rewrite。InfinityFree 正好满足。

### 13.4 为什么不直接用完整文件

因为 InfinityFree 有单文件 10MB 限制：
- `ppw.wasm.code.unityweb` 28MB → 超了
- `ppw.data.unityweb` 12MB → 超了
- 只有 framework 512KB → 没问题

如果你的主机没有文件大小限制，直接传完整的 .unityweb 文件最简单，不需要 PHP 合并。

---

## 14. IndexedDB 与 Unity IDBFS 深入分析

### 14.1 什么是 IDBFS

IDBFS（IndexedDB File System）是 Emscripten 提供的一个文件系统后端，把文件存储在浏览器的 IndexedDB 中。

Unity WebGL 用 IDBFS 来模拟本地文件系统，让 Unity 的文件操作（File.ReadAllBytes 等）能在浏览器里工作。

### 14.2 IDBFS 的数据结构

```
IndexedDB 数据库：/idbfs
  └─ 对象仓库：FILE_DATA
      └─ 每条记录代表一个文件：
          {
            key: "/idbfs/path/to/file",     // 文件路径
            value: {
              contents: Uint8Array,          // 文件内容
              timestamp: Date,               // 修改时间
              mode: number,                  // 文件权限
              // ... 其他元数据
            }
          }
```

### 14.3 PlayerPrefs 文件路径

PlayerPrefs 在 IndexedDB 中的路径大致是：
```
/idbfs/Users/DefaultUser/AppData/LocalLow/[CompanyName]/[ProductName]/playerprefs.dat
```

本项目中：
```
/idbfs/Users/DefaultUser/AppData/LocalLow/Akos Makovics/Papery Planes/playerprefs.dat
```

**怎么找到准确的路径？**
1. 打开浏览器开发者工具
2. Application → IndexedDB → /idbfs → FILE_DATA
3. 浏览所有 key，找包含 "playerprefs" 的那个

### 14.4 为什么不能主动创建数据库

如果数据库不存在，`indexedDB.open()` 会创建一个新的空数据库。

但这个空数据库的结构和 Unity 期望的不一样：
- Unity 期望的版本号可能不同
- Unity 期望的对象仓库结构可能不同
- 可能有其他元数据缺失

**结果：** Unity 启动时读取数据库失败 → 各种断言失败 → 崩溃。

**正确做法：**
- 数据库不存在 → 不碰它，让 Unity 自己创建
- 数据库存在 → 才去修改里面的数据

### 14.5 怎么判断数据库是否存在

**方法 1：`indexedDB.databases()`**
```js
if (indexedDB.databases) {
    const dbs = await indexedDB.databases();
    const exists = dbs.some(db => db.name === '/idbfs');
}
```

优点：无副作用，不会创建数据库。
缺点：浏览器兼容性（Chrome 71+、Firefox 109+、Safari 15.4+）。

**方法 2：open + 立即 abort**
```js
const req = indexedDB.open('/idbfs');
req.onupgradeneeded = (e) => {
    // 触发 upgradeneeded = 数据库不存在
    e.target.transaction.abort();  // 中止，不创建
};
req.onsuccess = (e) => {
    // 直接成功 = 数据库已存在
    e.target.result.close();
};
```

优点：兼容性更好。
缺点：稍微有点 hacky。

### 14.6 Uint8Array vs ArrayBuffer

这是一个非常容易踩的坑。

**两者的区别：**
- `ArrayBuffer`：原始的二进制数据缓冲区，不能直接读写
- `Uint8Array`：ArrayBuffer 的"视图"，可以按字节读写

```js
const buf = new ArrayBuffer(4);   // 4 字节的原始缓冲区
const view = new Uint8Array(buf); // 8 位无符号整数视图
view[0] = 0x3F;  // 可以直接操作
```

**Unity 写入时的类型：**
- 有时候是 Uint8Array
- 有时候是 ArrayBuffer
- 可能和 Unity 版本、数据大小、运行时状态有关

**为什么类型很重要：**
- 如果你把 ArrayBuffer 改成 Uint8Array 再存回去
- Unity 下次读取时可能解析失败
- 导致 "invalid handle for stdin" 崩溃

**正确做法：**
```js
const isAB = obj.contents instanceof ArrayBuffer;
const data = new Uint8Array(obj.contents);
// ... 修改 data ...
obj.contents = isAB ? data.buffer : data;  // 保持原始类型
store.put(obj, pk);
```

**经验法则：** 修改 IndexedDB 中的二进制数据时，**严格保持原始类型**。

---

## 15. Unity PlayerPrefs 完整格式详解

### 15.1 文件头

PlayerPrefs 数据文件有一个文件头，格式大致如下：

```
┌───────────┬───────────┬──────────────┐
│  Magic    │  Version  │  数据部分      │
│ (?)字节   │ (?)字节   │  键值对列表    │
└───────────┴───────────┴──────────────┘
```

文件头的具体格式在不同 Unity 版本中可能有差异。

**注意：** 我们的修改方法（搜索特征字节串）不依赖对文件头的理解，直接在数据里找 "Coins" 的特征序列，所以对文件头变化不敏感。

### 15.2 键值对完整格式

每个键值对的完整结构：

```
┌──────────────┬─────────────────┬─────────┬──────────────┐
│ 键名长度       │  键名字符串      │ 类型标记  │  值数据       │
│ (1 byte)     │  (N bytes)      │(1 byte) │ (M bytes)    │
└──────────────┴─────────────────┴─────────┴──────────────┘
```

**键名长度：** 1 字节无符号整数，最大 255。

**键名字符串：** UTF-8 编码（或 ASCII，因为 Unity 键名通常是英文）。

**类型标记：**

| 标记值 | 类型 | 值数据格式 |
|--------|------|-----------|
| 0x00 | 未知/空 | — |
| 0x01 | 未知 | — |
| 0xFC | string | 4 字节长度前缀 + UTF-8 字符串内容 |
| 0xFD | float | 4 字节 IEEE 754 单精度浮点数，小端序 |
| 0xFE | int | 4 字节有符号整数，小端序 |

**注意：** 这些类型标记是通过逆向工程得出的，不同 Unity 版本可能有差异。

### 15.3 int 类型详解

```
标记：0xFE
长度：4 字节
格式：int32 小端序
范围：-2147483648 到 2147483647
```

**小端序的含义：** 低字节存放在低地址。

例如 999999 = 0x000F423F：
```
内存地址递增 →
地址0: 0x3F  (最低字节)
地址1: 0x42
地址2: 0x0F
地址3: 0x00  (最高字节)
```

**读取方法：**
```js
var cur = data[vi] | (data[vi+1]<<8) | (data[vi+2]<<16) | (data[vi+3]<<24);
```

**负数的情况：** 如果数值可能为负，需要特殊处理（高位补 1）。但金币不会是负数，所以不用管。

### 15.4 float 类型详解

```
标记：0xFD
长度：4 字节
格式：IEEE 754 单精度浮点数，小端序
```

**读取方法：**
```js
var buf = new ArrayBuffer(4);
var view = new Uint8Array(buf);
for (var i = 0; i < 4; i++) view[i] = data[vi + i];
var f = new Float32Array(buf)[0];
```

### 15.5 string 类型详解

```
标记：0xFC
格式：4 字节长度前缀（小端序 int32） + N 字节 UTF-8 字符串
```

**示例：** "Hello"
```
FC 05 00 00 00  48 65 6C 6C 6F
│  └──────┘     └──────┘
│  长度=5         "Hello"
类型标记
```

### 15.6 特征搜索的可靠性

**为什么搜索 "Coins" 的特征序列是可靠的？**

1. **键名是字符串**："Coins" 这几个字母在二进制中很容易识别
2. **有长度前缀**：前面的 05 确认了字符串长度是 5
3. **有类型标记**：后面的 0xFE 确认是 int 类型
4. **组合概率极低**：05 43 6F 69 6E 73 FE 这个 7 字节序列，在随机数据中出现的概率是 1/(256^7) ≈ 1/(7×10^16)，几乎不可能误匹配

**注意事项：**
- 如果有其他键名也包含 "Coins"（比如 "CoinsSpent"），可能误匹配
- 但搜索时用了长度前缀（05 = 长度正好是 5），所以长度不对的会排除
- 只要键名正好是 "Coins" 且类型是 int，就是唯一的

### 15.7 其他可修改的字段

理论上，只要知道键名和类型，任何 PlayerPrefs 字段都能改。

**常见的可修改字段：**

| 字段名 | 类型 | 修改效果 |
|--------|------|---------|
| Coins | int | 金币数量 |
| HighScore | int | 最高分 |
| UnlockedPlanes | string/int | 解锁的飞机 |
| SelectedPlane | int | 当前选择的飞机 |
| Volume | float | 音量设置 |

**怎么找到字段名？**
1. 先玩一会儿游戏，让它保存存档
2. 打开 IndexedDB，导出 PlayerPrefs 数据
3. 在二进制数据里搜索可读的字符串
4. 根据上下文判断类型和含义

---

## 16. 安全与性能考量

### 16.1 安全考量

#### 路径遍历攻击
**风险：** 如果直接用 `$_GET['f']` 读文件，攻击者可以构造 `?f=../../etc/passwd`。

**防护：** 白名单机制，只允许访问预定义的文件。

#### XSS 攻击
**风险：** 游戏页面有没有 XSS 漏洞？

**评估：** 本项目没有用户输入输出，没有数据库，纯静态页面，XSS 风险很低。

#### 金币修改的安全性
**风险：** 玩家能不能"作弊"改金币？

**评估：** 这是单机游戏，金币本来就存在玩家本地。玩家想改就能改（用浏览器开发者工具就行）。我们的功能只是把手动操作自动化了，不存在"安全问题"。

### 16.2 性能考量

#### PHP 合并的性能开销
- 每次请求都要读取多个文件并合并输出
- 有一定的 PHP 解析开销
- 但浏览器缓存 30 天，实际只有首次加载会走 PHP

**优化建议：**
- 如果流量大，可以考虑用 CDN 缓存
- 或者把完整文件放到对象存储（OSS/S3）上，CDN 加速

#### 内存占用
- PHP 脚本不会把整个文件读进内存
- 每次只读需要的分块部分
- 内存占用 ≈ 最大分块大小（约 10MB）

#### WASM 编译
- 首次加载时，浏览器需要编译 WASM
- 编译时间：几秒到几十秒（取决于设备性能）
- 编译后浏览器会缓存编译结果（如果 MIME 类型正确）

---

## 17. 附录：常用调试命令和工具

### 17.1 浏览器控制台常用命令

```js
// 清掉 Unity 的 IndexedDB（游戏崩溃时用）
indexedDB.deleteDatabase('/idbfs');
location.reload();

// 查看所有 IndexedDB 数据库
indexedDB.databases().then(dbs => console.log(dbs));

// 查看 PlayerPrefs 内容（需要游戏已经保存过）
var req = indexedDB.open('/idbfs');
req.onsuccess = function(e) {
    var db = e.target.result;
    var tx = db.transaction('FILE_DATA', 'readonly');
    var store = tx.objectStore('FILE_DATA');
    store.getAllKeys().onsuccess = function() {
        console.log('所有文件:', this.result);
        // 找 playerprefs 那个
        var pk = this.result.find(k => k.indexOf('PlayerPrefs') !== -1 || k.indexOf('playerprefs') !== -1);
        if (pk) {
            store.get(pk).onsuccess = function(ev) {
                console.log('PlayerPrefs 数据:', new Uint8Array(ev.target.result.contents));
            };
        }
    };
};
```

### 17.2 分块和合并验证

**验证分块是否正确（合并后和原文件一样）：**

```python
# Python 验证脚本
def verify_chunks(original_file, chunk_prefix, chunk_count):
    with open(original_file, 'rb') as f:
        original = f.read()
    
    merged = bytearray()
    for i in range(chunk_count):
        with open(f"{chunk_prefix}.part{i}", 'rb') as f:
            merged.extend(f.read())
    
    if original == merged:
        print("✅ 分块正确，合并后和原文件一致")
        print(f"   原文件大小: {len(original)} 字节")
        print(f"   合并后大小: {len(merged)} 字节")
    else:
        print("❌ 分块有问题！")
        print(f"   原文件大小: {len(original)} 字节")
        print(f"   合并后大小: {len(merged)} 字节")
```

### 17.3 PHP 脚本调试

如果 unityweb.php 有问题，可以加调试输出：

```php
// 在脚本开头加
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 或者把错误写到日志
file_put_contents('debug.log', date('Y-m-d H:i:s') . ' ' . $_GET['f'] . "\n", FILE_APPEND);
```

### 17.4 .htaccess 调试技巧

**逐步排查法：**
1. 先把 .htaccess 清空，确认基础功能正常
2. 逐行添加配置，每加一行测试一次
3. 遇到 500 错误，说明刚加的那行有问题
4. 把有问题的行删掉或注释掉，继续

**常见的 500 原因：**
- 指令拼写错误
- 模块没启用（要用 IfModule 包起来）
- 路径错误
- 语法错误（引号不匹配、括号不闭合等）

### 17.5 常用工具

| 工具 | 用途 |
|------|------|
| HxD / 010 Editor | 十六进制编辑器，查看和修改二进制文件 |
| Unity Assets Bundle Extractor | 解包 Unity 资源文件 |
| ILSpy / dnSpy | 反编译 .NET DLL（Unity 游戏的 C# 代码） |
| il2cppdumper | 从 IL2CPP 构建中提取符号信息 |
| Postman / curl | 测试 HTTP 请求，检查响应头 |

---

---

## 18. WebAssembly 原理深入

### 18.1 什么是 WebAssembly

WebAssembly（简称 WASM）是一种低级字节码格式，可以在浏览器中以接近原生的速度运行。

**特点**：
- 二进制格式，体积小，加载快
- 静态类型，性能可预测
- 沙箱环境，安全
- 可以和 JavaScript 互操作
- 开放标准，W3C 推荐

Unity WebGL 构建的核心就是把游戏引擎和游戏代码编译成 WASM。

### 18.2 WASM vs JavaScript

| 特性 | JavaScript | WebAssembly |
|------|-----------|-------------|
| 格式 | 文本源码 | 二进制字节码 |
| 类型 | 动态类型 | 静态类型 |
| 解析 | 需要解析+编译 | 解码+验证很快 |
| 性能 | JIT 优化，但可能去优化 | 性能稳定，接近原生 |
| 内存管理 | GC 自动管理 | 手动管理（线性内存） |
| 互操作 | - | 可以和 JS 互相调用 |
| 体积 | 文本，较大 | 二进制，较小 |

**为什么 Unity 用 WASM 而不是 JS？**
- Unity 引擎是 C++ 写的，体量巨大
- 编译成 JS 体积会大很多，运行也慢
- WASM 性能接近原生，适合重型 3D 游戏
- C++ → LLVM → WASM 的工具链成熟（Emscripten）

### 18.3 WASM 执行流程

```
.wasm 文件
   ↓
1. 获取（Fetch）
   ↓
2. 解码 + 验证（Decode + Validate）
   ↓  验证类型安全、栈平衡等
3. 编译（Compile）
   ↓  编译成本地机器码
4. 实例化（Instantiate）
   ↓  创建模块实例，初始化内存
5. 执行（Execute）
   ↓  调用导出函数
```

**为什么首次加载慢？**
- 第 2-4 步都需要时间
- Unity 的 WASM 文件有 10+MB，编译时间长
- 浏览器会缓存编译结果，第二次加载快很多

### 18.4 WASM 内存模型

WASM 的内存是一块连续的**线性内存（Linear Memory）**：

```
WebAssembly.Memory
┌─────────────────────────────────────────┐
│  0x00000000                             │
│    栈（Stack）                           │
│    ...                                  │
│    堆（Heap）                            │
│    ...                                  │
│    数据段（Data）                        │
│  0xXXXXXXXX                             │
└─────────────────────────────────────────┘
```

**特点**：
- 内存是一个 `WebAssembly.Memory` 对象
- JS 可以通过 `Uint8Array`、`Uint32Array` 等视图读写
- 内存以 64KB 页为单位增长
- 默认 16MB，可以动态增长
- 32 位地址空间，最大 4GB（实际通常 2GB）

**Unity 的内存布局**：
- 低地址：栈空间
- 中间：托管堆（Mono/IL2CPP 的对象）
- 高地址：原生堆（引擎 C++ 对象）

### 18.5 JS 与 WASM 互操作

**JS 调用 WASM**：
```js
// 导入 WASM 模块
const wasm = await WebAssembly.instantiateStreaming(fetch('game.wasm'), imports);

// 调用 WASM 导出函数
const result = wasm.exports.add(1, 2);
```

**WASM 调用 JS**：
```js
// 在 import 对象中提供函数
const imports = {
    env: {
        console_log: function(ptr) {
            console.log(UTF8ToString(ptr));
        }
    }
};
```

**互操作的性能开销**：
- JS → WASM：开销很小（几乎和普通函数调用一样）
- WASM → JS：开销稍大（需要跨越边界）
- 传字符串、对象等复杂类型开销大（需要序列化）

所以 Unity 尽量减少 WASM → JS 的调用次数。

### 18.6 WASM 编译优化级别

Emscripten 提供不同的优化级别：

| 级别 | 说明 | 体积 | 性能 |
|------|------|------|------|
| -O0 | 无优化 | 最大 | 最慢 |
| -O1 | 基本优化 | 大 | 慢 |
| -O2 | 标准优化 | 中 | 快 |
| -O3 | 激进优化 | 小 | 最快 |
| -Os | 优化大小 | 最小 | 较快 |
| -Oz | 极致大小优化 | 最小+ | 中等 |

Unity 默认用什么级别取决于发布设置（Debug/Release）。

### 18.7 WASM 未来：WASM GC、WASI 等

WebAssembly 还在不断演进：

- **WASM GC**：支持垃圾回收（可以更好地运行 Java/C# 等语言）
- **WASI**：WebAssembly 系统接口（可以在浏览器外运行）
- **SIMD**：单指令多数据（向量化加速）
- **Threads**：多线程支持（原子操作、SharedArrayBuffer）
- **Exception Handling**：异常处理（减少 try/catch 开销）

这些特性会让 Unity WebGL 的性能越来越好。

---

## 19. Unity WebGL 内存模型详解

### 19.1 内存分区

Unity WebGL 的内存分为几个区域：

```
┌─────────────────────────────────────┐
│  WASM 线性内存                        │
│  ┌───────────────────────────────┐  │
│  │  栈（Stack）                  │  │
│  │  - 函数调用栈                  │  │
│  │  - 局部变量                    │  │
│  ├───────────────────────────────┤  │
│  │  托管堆（Managed Heap）        │  │
│  │  - C# 对象                     │  │
│  │  - IL2CPP 生成的对象            │  │
│  ├───────────────────────────────┤  │
│  │  原生堆（Native Heap）         │  │
│  │  - 引擎 C++ 对象               │  │
│  │  - 纹理、网格、音频等资源        │  │
│  └───────────────────────────────┘  │
└─────────────────────────────────────┘
```

### 19.2 托管堆 vs 原生堆

| 特性 | 托管堆 | 原生堆 |
|------|--------|--------|
| 分配方式 | `new` C# 对象 | C++ `new` / `malloc` |
| 垃圾回收 | IL2CPP GC | 手动管理 |
| 大小 | 通常几十 MB | 通常更大 |
| 对象类型 | C# 类对象 | 引擎内部对象 |
| 调试难度 | 较高 | 高 |

**PlayerPrefs 存在哪里？**
- PlayerPrefs 数据在 C# 层（托管堆）有一份缓存
- 持久化到 IDBFS（IndexedDB）
- 修改 IndexedDB 后，下次游戏加载时会读进去

### 19.3 IL2CPP 的 GC

IL2CPP 使用**分代垃圾回收器（Generational GC）**：

- **第 0 代（Young）**：新创建的对象，回收频繁，快
- **第 1 代（Mid）**：幸存了一次 GC 的对象
- **第 2 代（Old）**：长期存活的对象，回收少，慢

**GC 触发时机**：
- 分配内存时空间不够
- 手动调用 `System.GC.Collect()`
- 场景切换时
- 内存压力大时

**GC 卡顿问题**：
- GC 时游戏会暂停（Stop-The-World）
- 大堆的 Full GC 可能卡几十毫秒
- 优化方法：减少对象分配、用对象池、避免字符串拼接

### 19.4 内存限制

浏览器对 WASM 内存有限制：

| 浏览器 | 32位 | 64位 |
|--------|------|------|
| Chrome | 2GB | 4GB |
| Firefox | 2GB | 4GB |
| Safari | 1GB | 2GB |
| 移动端 | 通常 512MB-1GB | 更少 |

Unity WebGL 游戏容易遇到内存不足的问题，尤其是移动端。

### 19.5 内存优化策略

1. **减少纹理大小**：纹理是内存大户
2. **压缩纹理**：用 ASTC、ETC2 等压缩格式
3. **降低模型精度**：减少顶点数
4. **对象池**：复用对象，减少 GC
5. **及时卸载资源**：`Resources.UnloadUnusedAssets()`
6. **减少音频质量**：降低采样率、用压缩格式
7. **减小初始内存**：Player Settings 里调整

### 19.6 内存泄露排查

**常见的内存泄露原因**：
- 事件/委托没有取消订阅
- 静态引用持有对象
- 协程没有停止
- 资源没有卸载（AssetBundle 泄露）
- 原生对象没有释放（NativeObject 泄露）

**排查工具**：
- Unity Profiler（Memory 模块）
- Chrome DevTools → Memory 面板
- 浏览器任务管理器看内存增长

---

## 20. IL2CPP 编译细节深入

### 20.1 IL2CPP 是什么

IL2CPP（Intermediate Language To C++）是 Unity 的技术，把 .NET 中间语言（CIL）转换成 C++ 代码，再编译成本地代码。

```
C# 源码 → CIL (.dll) → C++ 代码 → 原生机器码 (WASM/x86/ARM)
```

**为什么不直接用 Mono JIT？**
- 很多平台不允许 JIT（iOS、WebAssembly 等）
- AOT（预先编译）性能更好
- 可以做更多的优化（因为能看到全部代码）

### 20.2 IL2CPP 编译流程详解

**步骤 1：C# → DLL（Mono 编译器）**
```
C# 源码 (.cs)
    ↓ mcs 或 csc
中间语言 (.dll / .exe)
```

**步骤 2：DLL → C++（IL2CPP 转换器）**
```
CIL 字节码
    ↓
1. 加载和解析程序集
2. 分析类型和方法
3. 生成 C++ 代码（每个方法对应一个 C++ 函数）
4. 生成元数据表（用于反射、GC 等）
    ↓
C++ 源文件 (.cpp / .h)
```

**步骤 3：C++ → 原生代码（C++ 编译器）**
```
C++ 代码
    ↓ Emscripten (Clang/LLVM) → WASM
    ↓ MSVC → Windows x86/x64
    ↓ Clang → macOS / iOS
    ↓ GCC → Android / Linux
原生机器码
```

### 20.3 IL2CPP 生成的 C++ 代码长什么样

一个简单的 C# 方法：
```csharp
public int Add(int a, int b)
{
    return a + b;
}
```

IL2CPP 生成的 C++ 大概是这样：
```cpp
extern "C" int32_t MyClass_Add_mXXXX(MyClass_t* __this, int32_t ___a, int32_t ___b, const RuntimeMethod* method)
{
    int32_t V_0 = 0;
    int32_t V_1 = 0;
    V_0 = ___a;
    V_1 = ___b;
    return V_0 + V_1;
}
```

可以看到：
- 方法名被重整（mangled）了
- `this` 指针作为第一个参数
- 局部变量变成 C++ 局部变量
- 简单的操作直接翻译成 C++ 对应操作

### 20.4 IL2CPP 的运行时

IL2CPP 不只是转译，还包含一个完整的运行时：

- **GC**：垃圾回收器（Boehm GC 或 SGen GC）
- **元数据**：类型信息、方法表（用于反射）
- **互操作**：P/Invoke、COM 互操作
- **线程**：线程管理、同步原语
- **异常**：异常处理（try/catch/finally）
- **泛型**：泛型类型和方法的特化

这些都是 Unity 自己实现的，不依赖 Mono 运行时。

### 20.5 为什么 IL2CPP 比 Mono 快

1. **AOT 编译**：编译时优化，运行时不需要 JIT
2. **C++ 优化**：C++ 编译器可以做更多优化（内联、循环展开等）
3. **无 JIT 开销**：没有 JIT 编译的停顿
4. **更好的内存布局**：值类型等可以更好地优化

**什么时候 Mono 更快？**
- 启动速度：Mono JIT 可能更快（不需要等 AOT 编译好的大量代码加载）
- 动态代码生成：Mono 支持 `System.Reflection.Emit`，IL2CPP 不支持

### 20.6 IL2CPP 的限制

IL2CPP 不支持一些 .NET 特性：

1. **Reflection.Emit**：动态生成代码不支持
2. **动态类型（`dynamic`）**：依赖 Reflection.Emit
3. **部分泛型共享**：有些泛型情况处理不好
4. **MarshalAs 的某些用法**：互操作限制
5. **调试体验差**：C++ 层的堆栈不太好读

**Strip（代码裁剪）**：
IL2CPP 会做代码裁剪，删掉没用的代码减小体积。但有时候会把需要的代码也裁掉（比如反射用到的类型），需要用 `link.xml` 保留。

---

## 21. Unity 网络与线程模型

### 21.1 WebGL 的单线程限制

WebGL 版本的 Unity 是**单线程**的，因为：
- JavaScript 是单线程的
- WASM 也是在 JS 线程中运行
- WebWorkers 可以做多线程，但通信开销大
- 多线程需要 SharedArrayBuffer，有些浏览器/环境不支持

**影响**：
- 所有逻辑（游戏逻辑、渲染、音频）都在一个线程
- 不能用多线程加速
- 加载资源也在主线程，会卡

**Unity 的应对**：
- 尽量把工作分散到多帧
- 协程（Coroutine）模拟异步
- 资源加载异步化

### 21.2 协程（Coroutine）原理

Unity 的协程不是多线程，是**迭代器 + 每帧调度**：

```csharp
IEnumerator MyCoroutine()
{
    Debug.Log("第一步");
    yield return null;  // 等一帧
    Debug.Log("第二步");
    yield return new WaitForSeconds(1);  // 等 1 秒
    Debug.Log("第三步");
}
```

**原理**：
- `IEnumerator` 是 C# 的迭代器
- `yield return` 暂停执行，返回当前状态
- Unity 每帧检查所有协程的状态
- 到时间了就继续执行下一步

**本质**：状态机 + 每帧轮询。不是真异步，是"伪多线程"。

### 21.3 Unity 渲染管线

WebGL 上的渲染流程：

```
C# 层（MonoBehaviour 更新）
    ↓
C++ 引擎层（场景剔除、排序）
    ↓
图形 API 层（OpenGL ES → WebGL）
    ↓
浏览器 WebGL 实现
    ↓
GPU
```

**WebGL 1.0 vs 2.0**：
- Unity 2019 默认用 WebGL 1.0
- WebGL 2.0 支持更多特性，但兼容性差一些
- 可以在 Player Settings 里切换

### 21.4 音频系统

Unity WebGL 的音频是怎么播放的？

- 不是用 Unity 自己的音频引擎直接输出
- 而是通过 Web Audio API 播放
- 音频数据解码后传给 Web Audio
- 有一定的延迟（浏览器的缓冲区）

**常见音频问题**：
- 首次播放需要用户交互（浏览器策略）
- 格式支持有限（MP3、WAV、OGG 等）
- 移动端可能有额外限制

### 21.5 输入系统

WebGL 的输入处理：

- 键盘：`keydown` / `keyup` 事件
- 鼠标：`mousedown` / `mouseup` / `mousemove` 事件
- 触摸：`touchstart` / `touchmove` / `touchend` 事件
- Unity 在 JS 层监听这些事件，传给 C++ 引擎层

**为什么 SendMessage 能用？**
- Unity 导出了 `SendMessage` 函数给 JS 调用
- JS 调用 `SendMessage(objectName, methodName, value)`
- Unity 内部找到对应的 GameObject，调用方法
- 这是 JS 和 Unity 通信的主要方式之一

---

## 22. Unity 逆向工程进阶

### 22.1 Unity 游戏的逆向层次

| 层次 | 目标 | 难度 | 工具 |
|------|------|------|------|
| 资源层 | 提取贴图、模型、音频 | ★ | AssetStudio、UABE |
| 代码层 | 反编译 C# 代码 | ★★★ | ILSpy、dnSpy |
| IL2CPP 层 | 还原 IL2CPP 的 C++ 代码 | ★★★★ | Il2CppDumper、IDA |
| 内存层 | 动态修改内存数据 | ★★★ | Cheat Engine、内存扫描 |
| 网络层 | 分析网络协议 | ★★★★ | Wireshark、Fiddler |

### 22.2 资源提取

Unity 的资源打包在 `.assets` 和 `.resource` 文件中。

**常见格式**：
- `sharedassets0.assets`：共享资源
- `level0`、`level1` 等：场景资源
- `resources.assets`：Resources 文件夹的资源
- `sharedassets0.resource`：大文件（纹理、音频等）

**提取工具**：
- **AssetStudio**：最流行的 Unity 资源提取工具
- **UABE**（Unity Assets Bundle Extractor）：可以编辑资源
- **UnityPy**：Python 库，写脚本用

**能提取什么**：
- 纹理（Texture2D）→ PNG/TGA
- 模型（Mesh）→ FBX/OBJ
- 音频（AudioClip）→ WAV/MP3
- 字体（Font）→ TTF
- 文本（TextAsset）→ 原文
- 材质、动画、Shader 等

### 22.3 代码反编译（Mono 版）

如果游戏是 Mono 编译的（不是 IL2CPP），直接提取 DLL 就能反编译：

1. 找到 `Managed/` 目录下的 `.dll` 文件
2. 用 ILSpy 或 dnSpy 打开
3. 可以看到几乎完整的 C# 代码
4. 甚至可以直接修改并保存

**Mono 版的特点**：
- DLL 里是完整的 CIL 字节码
- 反编译质量很高，变量名都保留了（如果没混淆）
- 修改也很容易

但 Papery Planes 是 WebGL 版，用的是 IL2CPP，所以不一样。

### 22.4 IL2CPP 逆向

IL2CPP 把 C# 转成了 C++ 再编译，所以逆向更难。

**步骤**：
1. 用 **Il2CppDumper** 提取元数据
   - 从 `global-metadata.dat` 提取类型、方法、字符串等信息
   - 生成 IDA 脚本和头文件
2. 用 **IDA Pro** 或 **Ghidra** 反汇编 WASM
3. 配合 Il2CppDumper 的结果定位函数
4. 分析和修改

**难度**：
- 比 Mono 版难很多
- 没有变量名，只有函数名
- 需要 C++ 和汇编基础
- 但比纯原生 C++ 好，因为有元数据可以辅助

### 22.5 内存修改原理

**为什么可以通过修改内存实现无限金币？**
- 游戏的数据（金币、血量等）都在内存里
- 找到数据的地址，改掉就行
- 游戏读取时就读到了修改后的值

**怎么找地址？**
1. 搜已知值（比如金币 100）
2. 改变数值（比如花掉一些）
3. 再搜新值
4. 重复几次，剩下的就是目标地址
5. 锁定这个地址的值（每次写回去）

**Unity 游戏的内存扫描难点**：
- 数据在托管堆，地址可能变（GC 移动对象）
- IL2CPP 的对象布局和 Mono 不一样
- WebGL 版更复杂，内存是 WASM 的线性内存

**为什么 Papery Planes 用 PlayerPrefs 修改更可靠？**
- PlayerPrefs 是持久化数据，格式固定
- 不依赖内存地址，不会因为版本更新而失效
- 修改存档比内存扫描更稳定

### 22.6 反作弊原理

**常见反作弊手段**：
1. **内存校验**：关键数据做校验和，被改了就检测到
2. **服务器验证**：重要数据存在服务器，本地只是显示
3. **混淆加壳**：代码混淆，增加逆向难度
4. **反调试**：检测调试器，发现就退出
5. **行为检测**：异常数据/行为检测

**为什么本游戏可以改？**
- 单机游戏，没有服务器验证
- 没有反作弊（小游戏一般都没有）
- PlayerPrefs 是明文存储的（或者简单加密）

---

## 23. 附录：Unity WebGL 优化最佳实践

### 23.1 体积优化

**代码体积**：
- ✅ 启用 Strip Engine Code（裁剪引擎代码）
- ✅ 用 IL2CPP 而不是 Mono（体积更小）
- ✅ 减少 .NET API 依赖
- ✅ 用 `link.xml` 配置裁剪

**资源体积**：
- ✅ 压缩纹理（ASTC / ETC2 / PVRTC）
- ✅ 降低纹理分辨率
- ✅ 压缩音频（MP3 / AAC）
- ✅ 减少网格顶点数
- ✅ 合并材质和纹理

**构建选项**：
- ✅ 用 Release 模式构建
- ✅ 启用 Compression（Gzip 或 Brotli）
- ✅ 关闭 Debug 功能
- ✅ 关闭 Profiler

### 23.2 加载速度优化

1. **分包加载**：把资源分成多个包，按需加载
2. **预加载**：显示 Loading 界面时预加载必要资源
3. **首屏资源最小化**：首屏只用必要的资源
4. **流式加载**：场景边玩边加载
5. **AssetBundle 缓存**：缓存下载过的资源
6. **WASM 缓存**：浏览器会缓存编译后的 WASM

### 23.3 性能优化

**CPU 优化**：
- 减少 Draw Call（静态批处理、GPU Instancing）
- 减少 GC 分配（对象池、避免字符串拼接）
- 优化 Update 中的逻辑（不要每帧做重计算）
- 用对象池复用 GameObject

**GPU 优化**：
- 减少顶点数
- 减少 Overdraw（透明物体叠加）
- 简化 Shader
- 降低分辨率（移动端）

**内存优化**：
- 及时卸载不用的资源
- 降低纹理质量
- 减少音频质量
- 减小初始堆大小

### 23.4 移动端优化

移动端性能比桌面差很多，需要特别优化：

1. **降低 DPR**：`Screen.dpi` 不要太高
2. **简化特效**：粒子数量减少
3. **降低画质**：纹理、阴影、后处理都降档
4. **内存限制**：更保守的内存使用
5. **触控优化**：确保触控响应及时

### 23.5 兼容性检查

**部署前检查清单**：

- [ ] 主流浏览器都测试过（Chrome、Firefox、Safari、Edge）
- [ ] 移动端测试过（iOS Safari、Android Chrome）
- [ ] 低网速下加载正常
- [ ] 内存不会持续增长（泄露检查）
- [ ] 控制台没有报错
- [ ] 声音播放正常
- [ ] 存档读写正常
- [ ] 刷新页面不会丢档
- [ ] 缩放窗口布局正常

---

## 24. 完整复刻部署指南（从零到上线不出错）

> 本章是给想要完整复刻部署的人准备的，按步骤一步步来，每步都有验证点，确保不出错。

### 24.1 前置准备

**你需要准备的东西**：

| 项目 | 要求 | 获取方式 |
|------|------|---------|
| Unity 构建产物 | `Build/` 目录下的所有文件 | Unity 编辑器 Build 出来 |
| PHP 虚拟主机 | 支持 PHP 5.6+、Apache、.htaccess | InfinityFree（免费）或其他 |
| FTP 工具 | FileZilla 或其他 FTP 客户端 | 免费下载 |
| 本地 HTTP 服务器 | 用于本地测试 | Python / Node.js / XAMPP |
| 分块工具 | 把大文件切成小块 | Python 脚本（下文提供） |

**Unity 构建产物清单（必须有）**：
```
Build/
├── PaperyPlanes.data.unityweb     # 游戏数据（通常最大）
├── PaperyPlanes.wasm.code.unityweb # WASM 代码
├── PaperyPlanes.wasm.framework.unityweb # JS 框架
└── PaperyPlanes.json              # 构建配置
```

> ⚠️ **重要检查**：确认这四个文件都存在，特别是 `.data.unityweb` 和 `.wasm.code.unityweb`，这两个通常超过 10MB。

### 24.2 第一步：本地搭建测试环境

**目标**：在本地跑起来，确认游戏本身没问题。

**方案 A：Python（推荐，最简单）**

```bash
# 进入游戏目录
cd papery-planes

# 启动本地 HTTP 服务器（Python 3）
python -m http.server 8080
```

**方案 B：Node.js**
```bash
npx http-server -p 8080
```

**✅ 验证点 1**：
- 浏览器打开 `http://localhost:8080/`
- 看到 Unity 加载进度条
- 进度条走到 100%，游戏正常启动
- 没有报错（F12 看 Console）

> ⚠️ **常见坑**：不要直接双击 `index.html` 用 `file://` 协议打开，Unity WebGL 必须用 HTTP 协议。

### 24.3 第二步：检查文件大小，确定是否需要分块

```bash
# Windows PowerShell
Get-ChildItem Build\*.unityweb | Select-Object Name, @{N='SizeMB';E={[math]::Round($_.Length/1MB,2)}}

# Linux/Mac
ls -lh Build/*.unityweb
```

**判断标准**：
- 所有文件 < 10MB → 不需要分块，直接上传
- 有文件 ≥ 10MB → 需要分块（InfinityFree 限制 10MB）

> InfinityFree 的单文件限制是 10MB。其他主机可能不同，根据实际情况调整。

### 24.4 第三步：分块操作（如需）

**用 Python 脚本分块**：

```python
# 保存为 split.py，放在 Build 目录同级
import os

def split_file(filepath, chunk_size=9 * 1024 * 1024):  # 9MB 一块，留余量
    """把大文件切成小块"""
    if not os.path.exists(filepath):
        print(f"❌ 文件不存在: {filepath}")
        return
    
    file_size = os.path.getsize(filepath)
    filename = os.path.basename(filepath)
    
    if file_size < chunk_size:
        print(f"⏭️  {filename} 只有 {file_size/1024/1024:.2f}MB，不需要分块")
        return
    
    chunk_num = 0
    with open(filepath, 'rb') as f:
        while True:
            chunk = f.read(chunk_size)
            if not chunk:
                break
            chunk_filename = f"{filepath}.part{chunk_num:03d}"
            with open(chunk_filename, 'wb') as out:
                out.write(chunk)
            chunk_num += 1
            print(f"✅ 生成 {chunk_filename} ({len(chunk)/1024/1024:.2f}MB)")
    
    print(f"\n✅ {filename} 分块完成，共 {chunk_num} 块")

# 需要分块的文件（根据实际情况修改）
split_file('Build/PaperyPlanes.data.unityweb')
split_file('Build/PaperyPlanes.wasm.code.unityweb')
split_file('Build/PaperyPlanes.wasm.framework.unityweb')
```

**运行**：
```bash
python split.py
```

**✅ 验证点 2**：
- 每个 `.partXXX` 文件都小于 10MB
- 所有分块大小加起来 ≈ 原文件大小
- 分块编号从 000 开始，连续不间断

> 💡 **为什么用 9MB 一块而不是 10MB？**
> 留 1MB 余量，防止上传时因为编码、metadata 等原因刚好卡在 10MB 线上。

### 24.5 第四步：准备 PHP 合并脚本

在 `Build/` 目录下创建 `unityweb.php`：

```php
<?php
/**
 * Unity WebGL 分块合并脚本
 * 用法：unityweb.php?f=filename.data.unityweb
 */

// 安全检查：防止目录遍历
$filename = isset($_GET['f']) ? basename($_GET['f']) : '';
if (empty($filename)) {
    header('HTTP/1.0 400 Bad Request');
    die('Missing filename');
}

// 允许的文件扩展名
$allowed_ext = ['unityweb', 'json', 'js', 'wasm'];
$ext = pathinfo($filename, PATHINFO_EXTENSION);
if (!in_array($ext, $allowed_ext)) {
    header('HTTP/1.0 403 Forbidden');
    die('File type not allowed');
}

$filepath = __DIR__ . '/' . $filename;

// 如果原文件存在，直接输出
if (file_exists($filepath)) {
    $size = filesize($filepath);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $size);
    header('Accept-Ranges: bytes');
    
    // 支持 Range 请求（断点续传）
    if (isset($_SERVER['HTTP_RANGE'])) {
        // ... Range 处理（完整版本见项目中的 unityweb.php）
    }
    
    readfile($filepath);
    exit;
}

// 原文件不存在，尝试从分块合并
$chunks = [];
$i = 0;
while (file_exists($filepath . '.part' . str_pad($i, 3, '0', STR_PAD_LEFT))) {
    $chunks[] = $filepath . '.part' . str_pad($i, 3, '0', STR_PAD_LEFT);
    $i++;
}

if (empty($chunks)) {
    header('HTTP/1.0 404 Not Found');
    die('File not found: ' . htmlspecialchars($filename));
}

// 计算总大小
$totalSize = 0;
foreach ($chunks as $chunk) {
    $totalSize += filesize($chunk);
}

// 发送响应头
header('Content-Type: application/octet-stream');
header('Content-Length: ' . $totalSize);
header('Accept-Ranges: bytes');

// 处理 Range 请求
if (isset($_SERVER['HTTP_RANGE'])) {
    // 解析 Range 头
    if (!preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        header('HTTP/1.1 416 Range Not Satisfiable');
        die;
    }
    
    $start = intval($matches[1]);
    $end = intval($matches[2]);
    
    if ($end == 0) $end = $totalSize - 1;
    if ($start > $end || $start >= $totalSize) {
        header('HTTP/1.1 416 Range Not Satisfiable');
        die;
    }
    
    header('HTTP/1.1 206 Partial Content');
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $totalSize);
    header('Content-Length: ' . ($end - $start + 1));
    
    // 输出指定范围的数据
    $remaining = $end - $start + 1;
    $offset = $start;
    
    foreach ($chunks as $chunk) {
        $chunkSize = filesize($chunk);
        if ($offset >= $chunkSize) {
            $offset -= $chunkSize;
            continue;
        }
        
        $fh = fopen($chunk, 'rb');
        fseek($fh, $offset);
        $data = fread($fh, min($remaining, $chunkSize - $offset));
        fclose($fh);
        echo $data;
        
        $remaining -= strlen($data);
        $offset = 0;
        
        if ($remaining <= 0) break;
    }
    exit;
}

// 完整输出：逐块读取并输出
foreach ($chunks as $chunk) {
    readfile($chunk);
}
```

> 💡 **项目中已有的 `unityweb.php` 是完整版本**，上面只是核心逻辑示意。直接用项目里的就行。

**✅ 验证点 3**：
- 本地访问 `http://localhost:8080/Build/unityweb.php?f=PaperyPlanes.data.unityweb`
- 能下载到完整的文件
- 下载的文件大小和原文件一样

### 24.6 第五步：配置 .htaccess

在游戏根目录创建 `.htaccess` 文件：

```apache
# 禁用目录列表
Options -Indexes

# 自定义错误页（可选）
ErrorDocument 404 /404.html

# Unity WebGL MIME 类型
<IfModule mod_mime.c>
    AddType application/octet-stream .unityweb
    AddType application/octet-stream .part
    AddType application/wasm .wasm
    AddType application/json .json
    AddType text/javascript .js
</IfModule>

# 缓存策略
<IfModule mod_expires.c>
    ExpiresActive On
    
    # HTML 不缓存（方便更新）
    ExpiresByType text/html "access plus 0 seconds"
    
    # Unity 构建文件缓存 7 天
    ExpiresByType application/octet-stream "access plus 7 days"
    
    # CSS/JS 缓存 7 天
    ExpiresByType text/css "access plus 7 days"
    ExpiresByType application/javascript "access plus 7 days"
    
    # 图片缓存 30 天
    ExpiresByType image/png "access plus 30 days"
    ExpiresByType image/jpeg "access plus 30 days"
    ExpiresByType image/svg+xml "access plus 30 days"
</IfModule>

# Gzip 压缩
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json
</IfModule>

# 安全头
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
</IfModule>
```

**✅ 验证点 4**：
- 上传 `.htaccess` 后，访问游戏页面没有 500 错误
- 用浏览器开发者工具看 Response Headers，有 `Content-Type: application/octet-stream`

> ⚠️ **常见坑**：`.htaccess` 写错一个字符就会导致整个网站 500 错误。如果出现 500，先把 `.htaccess` 清空，逐行添加排查。

### 24.7 第六步：修改 index.html 指向 PHP 脚本

找到 `index.html` 中 Unity 加载的配置，把 `dataUrl`、`codeUrl`、`frameworkUrl` 改成走 PHP 脚本：

```js
var buildUrl = "Build";
var loaderUrl = buildUrl + "/PaperyPlanes.loader.js";

// 关键：通过 unityweb.php 获取分块合并后的文件
var config = {
    dataUrl: buildUrl + "/unityweb.php?f=PaperyPlanes.data.unityweb",
    frameworkUrl: buildUrl + "/unityweb.php?f=PaperyPlanes.wasm.framework.unityweb",
    codeUrl: buildUrl + "/unityweb.php?f=PaperyPlanes.wasm.code.unityweb",
    // ... 其他配置
};
```

> 💡 如果文件不需要分块，就用原文件名，不走 PHP。

### 24.8 第七步：上传文件到服务器

**上传顺序（重要）**：

1. **先上传小文件**：`.htaccess`、`index.html`、`unityweb.php`、`.json`
2. **再上传分块文件**：`.part000`、`.part001` ...
3. **不要上传原文件**（超过 10MB 的那些传不上去）

**上传清单**：

| 文件 | 必传 | 说明 |
|------|------|------|
| `index.html` | ✅ | 主页面 |
| `.htaccess` | ✅ | Apache 配置 |
| `Build/*.json` | ✅ | 构建配置 |
| `Build/*.loader.js` | ✅ | Unity 加载器 |
| `Build/unityweb.php` | ✅ | 分块合并脚本 |
| `Build/*.unityweb.partXXX` | ✅ | 分块文件（小于 10MB） |
| `Build/*.unityweb` | ❌ | 原文件太大，不要传 |

**✅ 验证点 5**：
- FTP 工具显示所有文件上传成功
- 没有报错（特别是 550 错误）
- 在 FTP 中能看到所有文件

### 24.9 第八步：在线测试

访问你的域名，按以下顺序测试：

**测试 1：页面能否打开**
- 访问 `http://你的域名/games/papery-planes/`
- 应该看到 Unity 的加载界面
- 如果 404：检查路径是否正确
- 如果 500：检查 `.htaccess`

**测试 2：能否加载到 100%**
- 等待进度条走完
- 如果卡在 0%：很可能是 MIME 类型不对
- 如果卡在中间不动：刷新试试，可能是网络问题
- 如果报 DataView 错误：分块合并有问题（详见排错表）

**测试 3：游戏能否正常玩**
- 点击开始按钮
- 操作飞机飞一会儿
- 声音是否正常
- 金币是否显示正确

**测试 4：刷新后金币是否保存**
- 进入游戏，花掉一些金币
- 刷新页面
- 重新进入，看金币是否是花掉后的数量
- 如果金币清零了：PlayerPrefs 保存有问题

**测试 5：手机端测试**
- 用手机浏览器打开
- 看能否加载
- 操作是否正常
- 如果只有声音没画面：WebGL 兼容性问题

### 24.10 完整错误速查表

| 错误现象 | 可能原因 | 精确修复步骤 |
|---------|---------|-------------|
| 页面显示 500 Internal Server Error | `.htaccess` 语法错误 | 1. 把 `.htaccess` 内容全部删除<br>2. 逐行添加，每加一行刷新测试<br>3. 找到出错的那行删掉 |
| 进度条一直 0%，不动 | MIME 类型错误 / 文件找不到 | 1. F12 → Network 看 unityweb.php 的响应<br>2. 如果 404：检查文件名和路径<br>3. 如果是 text/html：检查 `.htaccess` 的 MIME 配置<br>4. 确认 PHP 脚本输出了 `Content-Type: application/octet-stream` |
| ERR_CONTENT_DECODING_FAILED | `.unityweb` 文件被错误地加了 gzip 头 | 1. 检查 `.htaccess` 中是否对 `.unityweb` 启用了 gzip<br>2. `unityweb.php` 中不要设置 `Content-Encoding: gzip`<br>3. Unity 2019+ 的 `.unityweb` 文件本身是 gzip 压缩的，但浏览器会自动处理，不需要手动加 |
| Uncaught RangeError: Offset is outside the bounds of the DataView | 分块合并后数据不对 / 顺序错了 | 1. 检查分块编号是否从 000 开始且连续<br>2. 下载合并后的文件，和本地原文件对比 MD5<br>3. 确认 PHP 脚本读取分块的顺序正确<br>4. 检查 Range 请求处理是否有 bug |
| Uncaught RuntimeError: memory access out of bounds | WASM 内存访问越界 | 1. 通常是游戏本身的 bug，不是部署问题<br>2. 试试不同的浏览器<br>3. 确认 Unity 构建版本正确 |
| Uncaught TypeError: Cannot read properties of undefined (reading 'cachedDecompressedFileSizes') | Unity loader.js 和 data 文件不匹配 | 1. 确认 `.json` 文件上传完整<br>2. 确认 loader.js 和 data 文件是同一次构建的<br>3. 清浏览器缓存重试 |
| 手机端只有声音没画面 | WebGL 不兼容 / 内存不够 | 1. 确认手机浏览器支持 WebGL<br>2. 试试切换到"电脑版网站"<br>3. Unity WebGL 在移动端兼容性确实不好，属于已知问题 |
| 金币修改后刷新还是 0 | IndexedDB 没改对 / 游戏覆盖了存档 | 1. 确认修改的是正确的 PlayerPrefs 文件路径<br>2. 确认修改时机是在游戏加载之前<br>3. 检查数据格式（必须是 Uint8Array，不是 ArrayBuffer）<br>4. 清掉 IndexedDB 重试：`indexedDB.deleteDatabase('/idbfs')` |
| 上传大文件失败 / 550 错误 | 文件超过 10MB 限制 | 1. 确认用了分块方案<br>2. 检查每个 `.part` 文件都小于 10MB<br>3. 不要传原始 `.unityweb` 文件 |
| 游戏加载慢 / 加载时间长 | 文件大 / 网络慢 / 首次编译 WASM | 1. 正常现象，Unity WebGL 就是大<br>2. 第二次加载会快很多（浏览器缓存）<br>3. 可以加个加载进度提示改善体验 |
| PHP 脚本返回 403 Forbidden | 文件名扩展名不在白名单 | 1. 检查 `unityweb.php` 中的 `$allowed_ext` 数组<br>2. 确认请求的文件扩展名在列表里 |
| 刷新页面后进度条从头开始 | 没有 Range 请求支持 | 1. 确认 `unityweb.php` 实现了 Range 处理<br>2. 检查响应头有 `Accept-Ranges: bytes` |

### 24.11 上线前验收清单

部署完成后，逐项检查：

- [ ] 桌面端 Chrome 能正常加载并游戏
- [ ] 桌面端 Firefox 能正常加载并游戏
- [ ] 桌面端 Edge 能正常加载并游戏
- [ ] 进度条能走到 100%
- [ ] 游戏音效正常
- [ ] 金币/存档功能正常
- [ ] 刷新页面不会丢档
- [ ] 控制台没有红色错误
- [ ] Network 面板没有 404 / 500
- [ ] 手机端至少能加载（Unity WebGL 移动端兼容性有限）
- [ ] `.htaccess` 没有引起 500 错误
- [ ] 所有分块文件都上传完整
- [ ] 没有上传多余的源文件（超过 10MB 的）

---

**文档版本**：v1.3（完整复刻版）
**最后更新**：2026-10-05
**更新内容**：新增完整复刻部署指南（11 个步骤+验证点）、完整错误速查表（13 种常见错误精确修复）、上线前验收清单
