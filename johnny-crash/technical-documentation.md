# Johnny Crash 技术文档

> **游戏名称**：Johnny Crash: Stuntman Does Texas（大炮飞人之征服德州）
> **类型**：Flash 游戏（SWF）
> **技术方案**：Ruffle WebAssembly 模拟器 + 原版 SWF 二进制修改
> **部署方式**：纯静态 HTML，可部署到任何虚拟主机（InfinityFree、虚拟主机等）

---

## 目录

1. [项目概述](#1-项目概述)
2. [文件结构](#2-文件结构)
3. [Ruffle Flash 模拟器集成](#3-ruffle-flash-模拟器集成)
4. [CDN 多源自动切换](#4-cdn-多源自动切换)
5. [SWF 加密分析：MochiCrypt](#5-swf-加密分析mochicrypt)
6. [RC4 解密原理与实现](#6-rc4-解密原理与实现)
7. [ABC 字节码反编译分析](#7-abc-字节码反编译分析)
8. [无限精力修改原理](#8-无限精力修改原理)
9. [二进制补丁方法](#9-二进制补丁方法)
10. [前端界面与交互](#10-前端界面与交互)
11. [游戏完整架构与工作原理详解](#11-游戏完整架构与工作原理详解)
12. [部署指南](#12-部署指南)
13. [故障排查](#13-故障排查)
14. [后续修改建议](#14-后续修改建议)
15. [附录 A：常用命令速查](#附录-a常用命令速查)

---

## 1. 项目概述

### 1.1 项目背景

Johnny Crash 是一款经典的 Flash 小游戏，玩家控制角色从大炮中发射，通过按住鼠标/空格键获得升力来延长飞行时间，途中可以撞到老鹰、衣架等物体来恢复能量。

由于 Flash 已被所有主流浏览器淘汰，本项目使用 **Ruffle**（WebAssembly 版 Flash 模拟器）来运行原版 SWF 文件，并通过**二进制修改**的方式实现了无限精力功能。

### 1.2 技术选型

| 组件 | 选择 | 原因 |
|------|------|------|
| Flash 模拟器 | Ruffle | 目前最成熟的开源 Flash 模拟器，WebAssembly 实现，性能好 |
| 加载方式 | CDN 多源自动切换 | 免费虚拟主机有单文件大小限制（通常 10MB），WASM 文件 14MB 无法上传 |
| 修改方式 | SWF 二进制补丁 | Ruffle 拦截合成事件，JS 无法模拟输入；直接修改 SWF 游戏逻辑最可靠 |
| 前端 | 原生 HTML + JS | 零依赖，部署简单 |

### 1.3 功能列表

- ✅ 原版游戏完整运行
- ✅ 无限精力模式（点击按钮切换）
- ✅ 设置自动保存（localStorage）
- ✅ 4 个 CDN 源自动切换，保证可用性
- ✅ 加载状态实时显示
- ✅ 响应式布局，适配各种屏幕

---

## 2. 文件结构

```
johnny-crash/
├── .htaccess                    # Apache 服务器配置（MIME类型、缓存、CORS）
├── index.html                   # 主页面，包含 Ruffle 加载和游戏逻辑
├── johnny-crash.swf             # 原版游戏 SWF（正常能量消耗）
└── johnny-crash-hacked.swf      # 修改版 SWF（无限精力）
```

### 文件说明

| 文件 | 大小 | 说明 |
|------|------|------|
| `.htaccess` | 2.3 KB | Apache 配置文件，确保 WASM/SWF 正确的 MIME 类型和 CORS 头 |
| `index.html` | 6.6 KB | 主页面，所有前端逻辑都在这里 |
| `johnny-crash.swf` | 907 KB | 原版游戏，CWS 格式（zlib 压缩） |
| `johnny-crash-hacked.swf` | 1079 KB | 修改版，FWS 格式（未压缩），能量消耗改为 0 |

> **注意**：Ruffle 模拟器通过 CDN 加载，不包含在本地文件中。如果需要本地自托管，需要额外下载 Ruffle 的 Self-Hosted 版本（约 28MB）。

---

## 3. Ruffle Flash 模拟器集成

### 3.1 什么是 Ruffle

Ruffle 是一个用 Rust 编写的 Flash 播放器模拟器，编译为 WebAssembly 后可以在浏览器中运行 SWF 文件，无需任何插件。

- 官网：https://ruffle.rs/
- GitHub：https://github.com/ruffle-rs/ruffle
- NPM 包：`@ruffle-rs/ruffle`

### 3.2 集成方式

Ruffle 提供两种集成方式：

#### 方式 A：自定义元素（推荐新手）

```html
<script src="ruffle.js"></script>
<ruffle-player src="game.swf" width="550" height="400"></ruffle-player>
```

简单直接，但灵活性较差。

#### 方式 B：JavaScript API（本项目使用）

```javascript
var ruffle = window.RufflePlayer.newest();
var player = ruffle.createPlayer();
container.appendChild(player);
player.load('game.swf').then(function() {
    console.log('加载成功');
}).catch(function(err) {
    console.error('加载失败:', err);
});
```

本项目使用 API 方式，原因：
1. 可以动态切换 SWF 文件（原版/修改版）
2. 可以精确控制加载时机
3. 可以捕获加载错误并显示友好提示

### 3.3 配置项

```javascript
window.RufflePlayer.config = {
    autoplay: "on",           // 自动播放
    backgroundColor: "#000",  // 背景色
    quality: "high",          // 画质
    letterbox: "on"           // 保持宽高比（信箱模式）
};
```

### 3.4 为什么不能用 JS 模拟输入

最初尝试用 JavaScript 定时模拟鼠标按下/松开事件来实现"无限精力"，但失败了：

```javascript
// ❌ 这样做没用，Ruffle 会拦截
player.dispatchEvent(new MouseEvent('mousedown'));
```

**原因**：Ruffle 出于安全考虑，只信任**用户真实触发**的事件（`event.isTrusted === true`），JavaScript 合成的事件会被直接忽略。

**解决方案**：直接修改 SWF 文件的二进制数据，把能量消耗改成 0。

---

## 4. CDN 多源自动切换

### 4.1 为什么需要多 CDN

免费虚拟主机（如 InfinityFree、kesug.com 等）通常有单文件大小限制（10MB 左右）。Ruffle 的 WASM 文件有 14MB，无法上传到服务器。因此采用 CDN 加载的方式。

但 CDN 也可能出问题：
- unpkg 在国内访问不稳定
- jsDelivr 某些地区被墙
- cdnjs 更新不及时

所以需要**多源自动切换**，哪个能用就用哪个。

### 4.2 实现原理

```
页面加载
  ↓
尝试第 1 个 CDN（unpkg）
  ├─ 8秒内加载成功 → 启动游戏
  └─ 超时或失败 → 尝试第 2 个 CDN（jsDelivr）
       ├─ 8秒内加载成功 → 启动游戏
       └─ 超时或失败 → 尝试第 3 个 CDN（cdnjs）
            ├─ 8秒内加载成功 → 启动游戏
            └─ 超时或失败 → 尝试第 4 个 CDN（jsDelivr 最新版）
                 ├─ 加载成功 → 启动游戏
                 └─ 全部失败 → 显示错误提示
```

### 4.3 CDN 列表

```javascript
var cdnList = [
    'https://unpkg.com/@ruffle-rs/ruffle@0.6.0/ruffle.js',
    'https://cdn.jsdelivr.net/npm/@ruffle-rs/ruffle@0.6.0/ruffle.js',
    'https://cdnjs.cloudflare.com/ajax/libs/ruffle/0.6.0/ruffle.js',
    'https://cdn.jsdelivr.net/npm/@ruffle-rs/ruffle/ruffle.js'  // 最新版兜底
];
```

### 4.4 关键代码

```javascript
var currentCdn = 0;
var loadTimer = null;

function loadRuffle() {
    if (currentCdn >= cdnList.length) {
        // 所有 CDN 都失败了
        showError('所有 CDN 源都无法访问');
        return;
    }

    var url = cdnList[currentCdn];
    var script = document.createElement('script');
    script.src = url;

    script.onerror = function() {
        // 加载失败，试下一个
        currentCdn++;
        clearTimeout(loadTimer);
        loadRuffle();
    };

    script.onload = function() {
        clearTimeout(loadTimer);
        if (window.RufflePlayer && typeof window.RufflePlayer.newest === 'function') {
            startGame();  // 加载成功，启动游戏
        } else {
            currentCdn++;
            loadRuffle();
        }
    };

    document.head.appendChild(script);

    // 8秒超时，自动换下一个
    loadTimer = setTimeout(function() {
        currentCdn++;
        loadRuffle();
    }, 8000);
}
```

### 4.5 版本选择

指定版本号（如 `@0.6.0`）比不指定版本更稳定，因为：
1. 缓存命中率高
2. 不会因为新版发布而引入 bug
3. CDN 源之间版本一致，切换不会出问题

最后一个源不加版本号作为兜底，万一指定版本在某个 CDN 上不存在，还能试试最新版。

---

## 5. SWF 加密分析：MochiCrypt

### 5.1 什么是 MochiCrypt

MochiCrypt 是 MochiMedia（当年最大的 Flash 游戏广告平台）提供的 SWF 加密方案，目的是：
- 防止游戏被盗版和篡改
- 强制显示 MochiAds 广告
- 统计游戏数据

### 5.2 加密原理

MochiCrypt 使用 **RC4 流密码**加密游戏主体代码，结构如下：

```
┌─────────────────────────────────┐
│  Loader SWF（未加密）            │
│  - 解密代码（RC4 算法）          │
│  - 解密密钥                      │
│  - MochiAds 广告代码             │
│  - DefineBinaryData 标签         │
│      └─ 加密的游戏数据           │
└─────────────────────────────────┘
```

运行流程：
1. Loader SWF 加载并运行
2. 从 DefineBinaryData 标签读取加密数据
3. 用内置密钥做 RC4 解密
4. 解密后的数据是 zlib 压缩的，解压得到真正的游戏 SWF
5. 加载运行真正的游戏

### 5.3 如何识别 MochiCrypt

可以从以下特征判断：

| 特征 | 说明 |
|------|------|
| 文件头是 `CWS` | 压缩过的 SWF |
| 包含 `DefineBinaryData` 标签 | 加密数据存放在这里 |
| 有 MochiAds 相关字符串 | `mochiads`、`MochiBot`、`MochiCrypto` 等 |
| SWF 大小异常小 | 因为游戏主体被压缩加密了 |

### 5.4 SWF 文件结构基础

SWF 文件由**文件头**和一系列**标签（Tag）**组成：

```
┌─────────────┐
│  SWF Header │  8 字节（签名+版本+文件大小+帧大小+帧速率+帧数）
├─────────────┤
│  Tag 1      │  标签类型 + 长度 + 数据
├─────────────┤
│  Tag 2      │
├─────────────┤
│  ...        │
├─────────────┤
│  End Tag    │  标记文件结束
└─────────────┘
```

**文件头签名**：
- `FWS`：未压缩的 SWF
- `CWS`：zlib 压缩的 SWF（文件头 8 字节不压缩，后面的标签数据被压缩）
- `ZWS`：LZMA 压缩的 SWF（少见）

---

## 6. RC4 解密原理与实现

### 6.1 RC4 算法简介

RC4 是一种流密码（Stream Cipher），由 Ron Rivest 在 1987 年设计。特点是：
- 算法极其简单
- 速度极快
- 安全性已被证明不够强（但用来加密游戏足够了）

### 6.2 RC4 算法步骤

RC4 分为两步：**密钥调度算法（KSA）** 和 **伪随机生成算法（PRGA）**。

#### 第 1 步：KSA（密钥调度）

初始化一个 256 字节的 S 盒（S-box），然后用密钥打乱它：

```python
S = list(range(256))  # S = [0, 1, 2, ..., 255]
j = 0
for i in range(256):
    j = (j + S[i] + key[i % len(key)]) & 0xFF
    S[i], S[j] = S[j], S[i]
```

#### 第 2 步：PRGA（生成密钥流）

用 S 盒生成和明文一样长的密钥流，然后逐字节异或：

```python
i = j = 0
keystream = []
for k in range(len(data)):
    i = (i + 1) & 0xFF
    j = (j + S[i]) & 0xFF
    S[i], S[j] = S[j], S[i]
    keystream.append(S[(S[i] + S[j]) & 0xFF])

# 解密：明文 = 密文 XOR 密钥流
result = [data[k] ^ keystream[k] for k in range(len(data))]
```

### 6.3 MochiCrypt 中的 RC4

MochiCrypt 的 RC4 实现有一点特殊：

1. **密钥长度是 32 字节**
2. **密钥藏在 DefineBinaryData 标签的数据末尾**
3. **只解密前 N 字节**（后面的数据不需要解密，或者是明文）

解密后的结构：
```
前 N 字节：RC4 解密后的数据（zlib 压缩的 SWF）
后 32 字节：密钥
```

### 6.4 Python 实现

```python
import zlib

def decrypt_mochicrypt(encrypted_data, key):
    """
    解密 MochiCrypt 加密的数据
    
    参数:
        encrypted_data: bytes, 加密的二进制数据
        key: bytes, 32 字节密钥
    
    返回:
        bytes, 解密后的原始 SWF 数据
    """
    data_arr = bytearray(encrypted_data)
    key_len = len(key)
    
    # ========== KSA：密钥调度 ==========
    S = list(range(256))
    j = 0
    for i in range(256):
        key_byte = key[i % key_len]
        j = (j + S[i] + key_byte) & 0xFF
        S[i], S[j] = S[j], S[i]
    
    # ========== PRGA：生成密钥流并解密 ==========
    i = j = 0
    for k in range(len(data_arr)):
        i = (i + 1) & 0xFF
        u = S[i]
        j = (j + u) & 0xFF
        v = S[j]
        S[i], S[j] = v, u
        data_arr[k] ^= S[(u + v) & 0xFF]
    
    # 解密后的数据是 zlib 压缩的，解压
    try:
        decompressed = zlib.decompress(bytes(data_arr))
        return decompressed
    except zlib.error:
        # 如果解压失败，可能密钥不对，或者解密的只是前半部分
        return bytes(data_arr)
```

### 6.5 如何找到密钥

有两种方法：

#### 方法 1：反编译 Loader SWF

用 JPEXS Free Flash Decompiler 打开 SWF，搜索 RC4 相关代码，找到密钥数组。

#### 方法 2：从二进制数据末尾提取

MochiCrypt 的密钥通常放在 DefineBinaryData 数据的最后 32 字节。可以这样找：

```python
def find_key(binary_data):
    """从 DefineBinaryData 数据末尾提取 32 字节密钥"""
    # 尝试最后 32 字节作为密钥
    key_candidate = binary_data[-32:]
    
    # 验证：用这个密钥解密前 1024 字节，看能不能解压
    test_data = binary_data[:-32]  # 去掉末尾的密钥
    decrypted = decrypt_rc4(test_data[:1024], key_candidate)
    
    # 检查解密后的数据是不是 zlib 格式（0x78 0x9C 是常见的 zlib 头）
    if decrypted[0] == 0x78 and decrypted[1] in (0x01, 0x5E, 0x9C, 0xDA, 0x20, 0x7D, 0xBB, 0xF9):
        return key_candidate
    
    return None
```

---

## 7. ABC 字节码反编译分析

### 7.1 什么是 ABC

ABC（ActionScript Bytecode）是 Flash 的字节码格式，类似 Java 的 class 文件。ActionScript 3.0 代码编译后生成 ABC 字节码，在 AVM2（ActionScript Virtual Machine 2）中运行。

### 7.2 ABC 文件结构

```
┌──────────────────┐
│  ABC Header      │  签名 "ABC" + 版本号
├──────────────────┤
│  Int Pool        │  整数字面量池
├──────────────────┤
│  UInt Pool       │  无符号整数字面量池
├──────────────────┤
│  Double Pool     │  双精度浮点数字面量池
├──────────────────┤
│  String Pool     │  字符串字面量池
├──────────────────┤
│  Namespace Pool  │  命名空间池
├──────────────────┤
│  Multiname Pool  │  多元名池（属性名、方法名等）
├──────────────────┤
│  Class Info      │  类定义
├──────────────────┤
│  Script Info     │  脚本定义
├──────────────────┤
│  Method Info     │  方法定义
├──────────────────┤
│  Metadata        │  元数据
└──────────────────┘
```

### 7.3 查找能量变量的方法

#### 思路

我们知道游戏里有"能量"（精力）的概念，飞行时消耗能量，撞到物体恢复能量。我们需要找到：

1. 能量变量的名称
2. 能量初始值
3. 消耗能量的代码
4. 恢复能量的代码

#### 步骤 1：搜索关键词

在反编译后的代码中搜索这些关键词：

```
energy, power, fuel, boost, stamina, break, fly, lift, rise, up
```

Johnny Crash 里能量变量是混淆过的，名字是 `§break§.§_-04§`（`§` 是 Flash 混淆器常用的字符）。

#### 步骤 2：查找数值

能量初始值是 212，飞行时每帧消耗 2.5。可以在 Double Pool 里搜索 2.5：

```python
def find_double_in_abc(abc_data, target_value):
    """在 ABC 的 Double Pool 中搜索目标值"""
    import struct
    
    # 跳过 ABC 头
    offset = 0
    # 解析 Int Pool...
    # 解析 UInt Pool...
    # 解析 Double Pool...
    
    # 简化版：直接在二进制里搜 double 的 8 字节表示
    target_bytes = struct.pack('<d', target_value)
    return [i for i in range(len(abc_data)-7) 
            if abc_data[i:i+8] == target_bytes]
```

#### 步骤 3：定位消耗代码

找到 2.5 这个 double 值后，搜索哪些指令引用了它。在 ActionScript 中，减操作通常是这样的：

```actionscript
// 能量 -= 2.5
§break§.§_-04§ -= 2.5;
```

对应的字节码大概是：
```
getlex §break§
dup
getproperty §_-04§
pushbyte 2.5
subtract
setproperty §_-04§
```

### 7.4 常用的反编译工具

| 工具 | 说明 |
|------|------|
| JPEXS Free Flash Decompiler | 最流行的 SWF 反编译工具，图形界面 |
| swftools | 命令行工具集，包含 swfdump 等 |
| RABCDAsm | ABC 字节码汇编/反汇编工具 |
| ffdec (JPEXS) | 就是上面那个，简称 ffdec |

---

## 8. 无限精力修改原理

### 8.1 核心思路

**把能量消耗从 2.5 改成 0。**

```
修改前：
  飞行时：能量 = 能量 - 2.5
  结果：能量越来越少，用完就掉下来

修改后：
  飞行时：能量 = 能量 - 0
  结果：能量永远不变，一直能飞
```

### 8.2 为什么不改恢复逻辑？

理论上也可以把"撞到老鹰恢复能量"的数值改大，但那样需要找到多处代码。直接把消耗改成 0 最简单，一处修改全局生效。

### 8.3 为什么不改初始值？

把初始能量改成 99999 也可以实现"接近无限"，但：
- 能量还是会慢慢减少（只是减少得很慢）
- 显示的能量条不会满（或者需要改显示逻辑）

改消耗为 0 更彻底，能量条永远是满的。

### 8.4 修改的副作用

- 游戏会变得非常简单，失去挑战性
- 但用户明确要求这个功能，所以没问题
- 不会影响游戏的其他功能

---

## 9. 二进制补丁方法

### 9.1 为什么用二进制补丁而不是重新编译

完全重新编译 SWF 需要：
1. 反编译得到 ActionScript 源码
2. 修改源码
3. 用 Flex SDK 重新编译
4. 重新加密（如果需要）

这个过程问题很多：
- 反编译的代码不一定能 100% 重新编译通过
- 混淆后的代码反编译质量很差
- 重新编译可能引入新的 bug

**二进制补丁更可靠**：直接在 SWF 的二进制数据里修改特定的字节，不需要重新编译。

### 9.2 修改 Double 值的方法

#### 原理

ActionScript 中的浮点数（Number 类型）是 64 位双精度浮点数（double），在 SWF 中以 **8 字节小端序**存储。

`2.5` 的 double 表示：
```
二进制：01000000 00000100 00000000 00000000 00000000 00000000 00000000 00000000
十六进制：40 04 00 00 00 00 00 00
小端序：00 00 00 00 00 00 04 40
```

`0.0` 的 double 表示：
```
十六进制：00 00 00 00 00 00 00 00
小端序：00 00 00 00 00 00 00 00
```

#### 搜索并替换

```python
import struct

def patch_double(swf_data, old_value, new_value):
    """
    在 SWF 数据中搜索 double 值并替换
    
    参数:
        swf_data: bytes, SWF 二进制数据
        old_value: float, 要替换的旧值
        new_value: float, 新值
    
    返回:
        bytes, 修改后的 SWF 数据
        int, 替换的数量
    """
    old_bytes = struct.pack('<d', old_value)
    new_bytes = struct.pack('<d', new_value)
    
    count = 0
    result = bytearray(swf_data)
    offset = 0
    
    while True:
        pos = result.find(old_bytes, offset)
        if pos == -1:
            break
        
        # 找到一个匹配，替换它
        result[pos:pos+8] = new_bytes
        count += 1
        offset = pos + 8
    
    return bytes(result), count
```

### 9.3 怎么确定只有一个 2.5？

在整个 SWF 中搜索 `2.5` 的 double 表示，如果只有一处，就可以安全替换。

Johnny Crash 里 `2.5` 的 double 值**只有一处**（就是能量消耗），所以直接替换不会误伤。

### 9.4 验证修改

修改后需要验证：
1. SWF 文件头是否正确（`FWS` 或 `CWS`）
2. 能否正常加载运行
3. 能量是否真的不消耗

### 9.5 完整修改流程

```
1. 读取原版 SWF 文件
   ↓
2. 如果是 CWS（压缩），先 zlib 解压得到 FWS
   ↓
3. 检查是否有 MochiCrypt 加密
   ├─ 有加密 → RC4 解密 → zlib 解压 → 得到游戏 SWF
   └─ 无加密 → 直接使用
   ↓
4. 在 SWF 中搜索 2.5 的 double 表示
   ↓
5. 如果找到且只有一处，替换为 0.0
   ↓
6. 保存为新的 SWF 文件（FWS 格式，不压缩）
   ↓
7. 测试验证
```

---

## 10. 前端界面与交互

### 10.1 页面布局

```
┌─────────────────────────────┐
│                             │
│     ┌─────────────────┐     │
│     │                 │     │
│     │   游戏画面       │     │
│     │   520 × 485     │     │
│     │                 │     │
│     └─────────────────┘     │
│                             │
│   鼠标左键/空格键 发射       │
│   按住获得升力               │
│                             │
│   [ ⚡ 无限精力 ]            │
│                             │
└─────────────────────────────┘
```

### 10.2 无限精力切换按钮

#### 功能
- 点击切换原版/无限精力版
- 状态保存在 localStorage
- 切换后自动刷新页面

#### 实现

```javascript
// 读取保存的状态
var infiniteEnergy = localStorage.getItem('jc_infinite') === '1';
var cheatBtn = document.getElementById('cheatBtn');

// 更新按钮样式
if (infiniteEnergy) {
    cheatBtn.classList.add('active');
}

// 点击切换
cheatBtn.addEventListener('click', function(e) {
    e.stopPropagation();  // 防止事件冒泡到游戏画面
    infiniteEnergy = !infiniteEnergy;
    cheatBtn.classList.toggle('active', infiniteEnergy);
    localStorage.setItem('jc_infinite', infiniteEnergy ? '1' : '0');
    window.location.reload();  // 刷新页面加载不同的 SWF
});
```

#### 为什么要刷新页面？

因为 Ruffle 加载 SWF 后，不能动态切换 SWF 文件（`load()` 方法重新加载可能有问题）。刷新页面是最简单可靠的方式。

### 10.3 加载状态显示

页面加载过程中，游戏区域中央显示加载状态：

- "准备中..." → 页面刚加载
- "加载模拟器中... 正在尝试：unpkg.com" → 正在加载 Ruffle
- 加载成功后隐藏

左下角显示当前使用的 CDN 源（小字，不显眼），方便排查问题。

### 10.4 响应式设计

游戏画面固定宽高比（520×485），用 CSS 实现自适应：

```css
.game-box {
    width: 520px;
    height: 485px;
    max-width: 100vw;
    max-height: 100vh;
}
```

小屏幕（手机）上游戏会自动缩小到屏幕宽度。

---

## 11. 游戏完整架构与工作原理详解

### 11.1 整体架构一览

整个游戏系统由 **4 层** 组成，从下到上：

```
┌─────────────────────────────────────────┐
│  第 4 层：前端交互层                      │
│  index.html（页面、按钮、CDN 切换）       │
├─────────────────────────────────────────┤
│  第 3 层：模拟器层                        │
│  Ruffle（WebAssembly Flash 模拟器）       │
├─────────────────────────────────────────┤
│  第 2 层：游戏 SWF 层                     │
│  johnny-crash.swf        （原版）         │
│  johnny-crash-hacked.swf （无限精力版）   │
├─────────────────────────────────────────┤
│  第 1 层：服务器层                        │
│  静态文件托管（GitHub Pages / InfinityFree / 本地）│
└─────────────────────────────────────────┘
```

每一层的职责：

| 层级 | 组成 | 干什么 | 谁做的 |
|------|------|--------|--------|
| 第 4 层 前端交互 | HTML + CSS + JS | 页面展示、按钮切换、CDN 加载、状态保存 | ✅ 我做的 |
| 第 3 层 模拟器 | Ruffle（CDN 加载） | 把 Flash 代码翻译成浏览器能跑的 WebAssembly | ❌ 开源项目 |
| 第 2 层 游戏 SWF | 两个 SWF 文件 | 游戏本身的逻辑和画面 | ❌ 原版游戏 + 我改的二进制 |
| 第 1 层 服务器 | 静态托管 | 提供文件下载 | ❌ 第三方平台 |

### 11.2 两个 SWF 文件的关系

游戏有 **两个 SWF 文件**，它们的关系是：

```
johnny-crash.swf（原版）
    └─ 能量消耗 = 2.5 / 每帧
    └─ 文件大小：907 KB（CWS 压缩格式）

johnny-crash-hacked.swf（修改版）
    └─ 能量消耗 = 0.0 / 每帧    ← 唯一区别
    └─ 文件大小：1079 KB（FWS 未压缩格式）
```

| 对比项 | 原版 | 无限精力版 |
|--------|------|-----------|
| 文件名 | `johnny-crash.swf` | `johnny-crash-hacked.swf` |
| 格式 | CWS（zlib 压缩） | FWS（未压缩） |
| 能量消耗 | 2.5 / 每帧 | 0.0 / 每帧 |
| 其他逻辑 | 完全一致 | 完全一致 |
| 怎么来的 | 原版游戏 | 从原版二进制修改而来 |

> 💡 **两个版本除了能量消耗这 8 个字节不一样，其他内容完全相同。** 修改量不到 0.001%。

### 11.3 无限精力的实现原理（完整链路）

从"点击按钮"到"游戏里能量不掉"，完整流程是这样的：

```
用户点击 "⚡ 无限精力" 按钮
    ↓
① localStorage 保存状态（jc_infinite = "1"）
    ↓
② 页面刷新（window.location.reload()）
    ↓
③ 页面加载时读取 localStorage
    infiniteEnergy = localStorage.getItem('jc_infinite') === '1'
    ↓
④ 根据状态选择 SWF 文件
    infiniteEnergy ? 'johnny-crash-hacked.swf' : 'johnny-crash.swf'
    ↓
⑤ Ruffle 加载对应的 SWF 文件
    player.load(swfFile)
    ↓
⑥ SWF 中能量消耗的代码
    原版：energy -= 2.5
    修改版：energy -= 0.0  ← 所以能量永远不变
    ↓
⑦ 游戏运行，能量条不减少 ✅
```

每一步的详细说明：

| 步骤 | 在哪执行 | 代码位置 | 说明 |
|------|---------|---------|------|
| ① 保存状态 | 浏览器 localStorage | `cheatBtn.addEventListener('click')` | 刷新后不丢失 |
| ② 刷新页面 | 浏览器 | `window.location.reload()` | 最简单可靠的切换方式 |
| ③ 读取状态 | 浏览器 | `localStorage.getItem('jc_infinite')` | 页面一加载就读 |
| ④ 选择 SWF | 浏览器 JS | `var swfFile = infiniteEnergy ? ...` | 决定加载哪个文件 |
| ⑤ Ruffle 加载 | Ruffle 模拟器 | `player.load(swfFile)` | 模拟器开始解析 SWF |
| ⑥ 能量消耗逻辑 | SWF 内部（ActionScript） | SWF 二进制中的字节码 | 修改的就是这一步的数值 |
| ⑦ 游戏效果 | 游戏运行时 | — | 能量条不动，无限飞 |

### 11.4 为什么要用"两个 SWF + 刷新页面"的方式？

理论上有 3 种实现无限精力的方法：

| 方法 | 原理 | 能行吗 | 为什么不用 |
|------|------|--------|-----------|
| **方法 A：JS 模拟鼠标按下** | 用 JS 不断发 mousedown 事件，让游戏以为一直按着 | ❌ 不行 | Ruffle 拦截合成事件，只信任真实用户操作 |
| **方法 B：动态切换 SWF** | 不刷新页面，调用 Ruffle 的 load() 重新加载 | ⚠️ 可能行 | Ruffle 的 load() 第二次调用可能有兼容性问题，不稳 |
| **方法 C：两个 SWF + 刷新页面** | 保存状态 → 刷新 → 加载不同 SWF | ✅ 最可靠 | 虽然多了一次刷新，但 100% 稳定 |

> 选方法 C 的原因：**可靠性 > 体验**。刷新一下虽然麻烦，但保证能用。

### 11.5 触摸操作是怎么实现的？

很多人以为触摸控制是我加的，其实不是。完整的解释：

| 功能 | 谁实现的 | 怎么实现的 |
|------|---------|-----------|
| **点屏幕 = 鼠标点击** | Ruffle 模拟器 | Ruffle 自动把 `touchstart` 转成 `mousedown`，`touchend` 转成 `mouseup` |
| **页面自适应手机屏幕** | 我做的 | CSS `max-width: 100vw` + viewport meta 标签 |
| **⚡ 无限精力按钮** | 我做的 | HTML 按钮 + JS 点击事件 + localStorage |
| **游戏里的画面和逻辑** | 原版 SWF | Flash 游戏本身，Ruffle 模拟运行 |

**为什么 Ruffle 能自动支持触摸？**

因为 Flash 游戏本来就是为鼠标设计的（只有点击、按住、松开）。Ruffle 在手机浏览器里运行时，自动做了一层映射：

```
手机触摸事件          Ruffle 转换         Flash 游戏接收
────────────          ────────          ────────────
touchstart    →      mousedown    →      按下鼠标
touchmove     →      mousemove    →      移动鼠标
touchend      →      mouseup      →      松开鼠标
```

所以这个游戏在手机上能直接玩，不需要额外加触摸控制代码。

> ⚠️ **注意**：这只适用于"点击/按住"类型的 Flash 游戏。如果是需要键盘、复杂鼠标操作的游戏，还是需要额外适配。

### 11.6 Ruffle 为什么要从 CDN 加载？

| 方案 | 优点 | 缺点 | 我们选哪个 |
|------|------|------|-----------|
| **本地自托管** | 不依赖第三方，加载快 | WASM 文件 14MB，免费主机传不了 | ❌ 不选 |
| **单个 CDN** | 实现简单 | 那个 CDN 挂了就全挂了 | ❌ 不选 |
| **多 CDN 自动切换** | 容错率高，总有一个能用 | 代码稍微复杂一点 | ✅ 选这个 |

CDN 切换的完整流程：

```
页面加载
  ↓
尝试 CDN 1（unpkg.com）
  ├─ 8秒内加载成功 → 启动游戏
  └─ 失败/超时 → 尝试 CDN 2（cdn.jsdelivr.net）
       ├─ 8秒内加载成功 → 启动游戏
       └─ 失败/超时 → 尝试 CDN 3（cdnjs.cloudflare.com）
            ├─ 8秒内加载成功 → 启动游戏
            └─ 失败/超时 → 尝试 CDN 4（jsdelivr 最新版）
                 ├─ 成功 → 启动游戏
                 └─ 全部失败 → 显示"加载失败"
```

### 11.7 游戏运行时的完整数据流

从用户打开网页到玩游戏，数据是这样流动的：

```
用户输入 URL
  ↓
浏览器请求 index.html ← 服务器返回
  ↓
浏览器解析 HTML，开始加载 Ruffle（CDN）
  ↓
Ruffle 加载成功（WebAssembly 初始化）
  ↓
Ruffle 请求 SWF 文件 ← 服务器返回 SWF 二进制
  ↓
Ruffle 解析 SWF：
  ├─ 解析文件头（FWS/CWS 签名、版本、大小）
  ├─ 解析各个 Tag（图形、音频、脚本...）
  └─ 解析 ABC 字节码（游戏逻辑）
  ↓
Ruffle 执行游戏初始化代码
  ↓
游戏画面显示，等待用户操作
  ↓
用户点击/触摸屏幕
  ↓
Ruffle 把事件转成 Flash 鼠标事件
  ↓
游戏逻辑处理：
  ├─ 发射大炮
  ├─ 每帧更新位置
  ├─ 每帧消耗能量（2.5 或 0.0）
  ├─ 碰撞检测（老鹰、衣架等）
  └─ 能量为 0 时角色下落
  ↓
Ruffle 每帧渲染画面
  ↓
用户看到游戏在运行 ✅
```

---

## 12. 部署指南

### 12.1 部署要求

- 任何支持静态文件托管的服务器
- Apache 服务器（推荐，`.htaccess` 才会生效）
- 如果是 Nginx，需要手动配置 MIME 类型

### 12.2 部署步骤

#### 方法 1：FTP 上传（推荐）

1. 用 FTP 客户端（FileZilla、WinSCP 等）连接服务器
2. 把 `johnny-crash/` 里的所有文件上传到网站根目录或子目录
3. 访问对应 URL

#### 方法 2：在线文件管理器

1. 登录主机控制面板
2. 打开文件管理器
3. 上传文件到 `htdocs` 目录

> ⚠️ **注意**：免费主机的在线文件管理器通常有单文件大小限制（10MB），但本项目最大的文件只有 1MB（SWF），所以没问题。

### 12.3 .htaccess 配置说明

`.htaccess` 文件是 Apache 服务器的配置文件，放上传到网站根目录或子目录都会自动生效。

主要配置了：

#### MIME 类型
```apache
AddType application/wasm .wasm
AddType application/x-shockwave-flash .swf
```
确保浏览器正确识别 WASM 和 SWF 文件。

#### CORS 头
```apache
Header set Access-Control-Allow-Origin "*"
```
允许跨域加载资源（虽然 Ruffle 是 CDN 的，但某些情况下需要）。

#### GZIP 压缩
```apache
AddOutputFilterByType DEFLATE text/html text/css application/javascript ...
```
开启文本文件的 gzip 压缩，加快加载速度。
> 注意：WASM 文件不开启 gzip，因为 WASM 本身已经是高度优化的二进制，再压缩反而可能更慢。

#### 浏览器缓存
```apache
ExpiresByType application/wasm "access plus 1 month"
ExpiresByType application/x-shockwave-flash "access plus 1 month"
```
设置静态资源的缓存时间，减少重复加载。

### 12.4 Nginx 配置（如果用 Nginx）

如果服务器是 Nginx，`.htaccess` 不生效，需要在 Nginx 配置文件中添加：

```nginx
# MIME 类型
types {
    application/wasm wasm;
    application/x-shockwave-flash swf;
}

# CORS
add_header Access-Control-Allow-Origin "*";

# 缓存
location ~* \.(wasm|swf)$ {
    expires 30d;
    add_header Cache-Control "public, immutable";
}

# gzip 压缩（WASM 不压缩）
gzip on;
gzip_types text/html text/css application/javascript application/x-shockwave-flash;
```

### 12.5 验证部署

上传完成后，访问网站地址，检查：

1. ✅ 页面正常显示
2. ✅ Ruffle 模拟器加载成功（左下角显示 CDN 域名）
3. ✅ 游戏正常运行
4. ✅ 点击"无限精力"按钮切换正常
5. ✅ 无限精力模式下能量不消耗

---

## 13. 故障排查

### 13.1 页面空白/黑屏

**可能原因**：
1. Ruffle 加载失败
2. SWF 文件路径不对
3. 浏览器不支持 WebAssembly

**排查方法**：
- 按 F12 打开开发者工具，看 Console 里的错误
- 看左下角的 CDN 信息，确认 Ruffle 是否加载成功
- 检查 Network 标签，看 SWF 文件是否 404

### 13.2 游戏加载失败

**错误信息："Something went wrong loading the SWF"**

**可能原因**：
1. SWF 文件路径不对
2. SWF 文件损坏
3. 跨域问题（CORS）
4. MIME 类型不对

**解决方法**：
1. 确认 SWF 文件在正确的位置
2. 检查 `.htaccess` 是否上传
3. 尝试直接访问 SWF 的 URL，看能不能下载
4. 如果是子目录部署，检查路径是否正确

### 13.3 无限精力不生效

**可能原因**：
1. 还在玩原版（没开启无限精力）
2. 缓存了旧版 SWF
3. 按钮点击没反应

**解决方法**：
1. 确认按钮是绿色的（active 状态）
2. 按 Ctrl+F5 强制刷新
3. 清浏览器缓存
4. 检查 `johnny-crash-hacked.swf` 文件是否存在

### 13.4 Ruffle 加载很慢

**可能原因**：
1. 当前 CDN 源访问慢
2. 网络不好

**解决方法**：
- 等一会儿，8 秒后会自动切换到下一个 CDN
- 多刷新几次，可能每次走的 CDN 不一样
- 如果所有 CDN 都慢，考虑本地自托管 Ruffle

### 13.5 WASM 文件上传后消失

**原因**：免费主机有单文件大小限制（通常 10MB），WASM 文件 14MB 超过了限制。

**解决方法**：
- 用 CDN 加载 Ruffle（本项目已经这样做了）
- 或者换一个没有文件大小限制的主机

### 13.6 手机上玩不了

**可能原因**：
1. 手机浏览器不支持 WebAssembly（旧手机）
2. 触摸操作不支持
3. 页面布局有问题

**说明**：
- 本游戏主要面向 PC 端
- 手机端可以打开，但操作需要鼠标/键盘
- 如果需要手机端触摸操作，需要额外开发触摸控制功能

---

## 14. 后续修改建议

### 14.1 如果想修改其他数值

方法和修改无限精力类似：

1. 找到要修改的数值（比如初始能量 212）
2. 在 SWF 二进制中搜索对应的数值
3. 替换成想要的值
4. 测试验证

**常见可修改的数值**：

| 数值 | 搜索方式 | 修改效果 |
|------|---------|---------|
| 初始能量 212 | 搜索整数 212 | 能量条初始更长/更短 |
| 老鹰恢复能量 | 搜索对应的数值 | 撞到老鹰恢复更多能量 |
| 重力 | 搜索对应的数值 | 角色下落更快/更慢 |
| 初始速度 | 搜索对应的数值 | 大炮发射速度更快 |

> ⚠️ **注意**：整数值可能有多处（比如初始值、显示值、重置值等），需要仔细区分。建议用 JPEXS 先反编译找到准确位置，再做二进制修改。

### 14.2 如果想解锁所有关卡

需要找到关卡解锁的判断逻辑，把判断条件改成永远为真。这比修改数值复杂，需要：

1. 反编译找到关卡相关的代码
2. 理解关卡解锁逻辑
3. 修改字节码中的条件跳转指令
4. 重新生成 SWF

### 14.3 如果想用本地 Ruffle（不依赖 CDN）

1. 从 https://ruffle.rs/#usage 下载 Self-Hosted 版本
2. 解压后把文件放到 `ruffle/` 文件夹
3. 修改 HTML，把 CDN 加载方式改成：
   ```html
   <script src="ruffle/ruffle.js"></script>
   ```
4. 不需要 `loadRuffle()` 函数了，直接在 `window.onload` 里创建播放器

> 注意：WASM 文件 14MB，需要确保主机支持上传这么大的文件。

### 14.4 如果想加手机触摸控制

需要在游戏画面上加一个透明的触摸区域，监听 `touchstart`/`touchend` 事件，然后调用 Ruffle 的 API 模拟鼠标：

```javascript
// 注意：这只是思路，实际可能不工作，因为 Ruffle 可能拦截非真实事件
var touchArea = document.getElementById('touchArea');
touchArea.addEventListener('touchstart', function(e) {
    e.preventDefault();
    // 模拟鼠标按下
});
touchArea.addEventListener('touchend', function(e) {
    e.preventDefault();
    // 模拟鼠标松开
});
```

> ⚠️ **重要**：Ruffle 的事件安全限制同样适用于触摸事件，合成的触摸事件也会被拦截。如果要做手机端，可能还是需要修改 SWF 本身的逻辑，或者用其他方式。

### 14.5 如果想加更多作弊功能

可以做成一个作弊菜单，每个作弊项对应一个修改后的 SWF：

- 无限精力
- 解锁所有关卡
- 无限金币
- 等等

但每个作弊项都需要单独做一个修改版 SWF，文件会比较多。

另一种方式是用 Ruffle 的 ActionScript 注入功能（如果支持的话），但这需要深入研究 Ruffle 的 API。

---

## 附录 A：常用命令速查

### 本地 HTTP 服务器

```cmd
:: Python（最简单）
python -m http.server 8080

:: PowerShell（需要管理员权限才能用 +:8080）
$l=New-Object Net.HttpListener;$l.Prefixes.Add('http://+:8080/');$l.Start();...
```

### 端口占用查询

```cmd
:: 查端口被谁占用
netstat -ano | findstr :8080

:: 按 PID 查进程名
tasklist /fi "PID eq 1234"

:: 杀进程
taskkill /F /PID 1234
```

### HTTP.sys 相关（PID=4 时）

```cmd
:: 查看请求队列（找真正的进程 PID）
netsh http show servicestate view="requestq"

:: 查看 URL 预留
netsh http show urlacl

:: 删除 URL 预留
netsh http delete urlacl url=http://+:8080/
```

### 防火墙

```powershell
# 放行端口
New-NetFirewallRule -DisplayName "Allow Port 8080" -Direction Inbound -LocalPort 8080 -Protocol TCP -Action Allow

# 删除规则
Remove-NetFirewallRule -DisplayName "Allow Port 8080"

# 查看规则
Get-NetFirewallRule -Direction Inbound | Where-Object { $_.DisplayName -like "*8080*" }
```

---

## 附录 B：SWF 相关工具

| 工具 | 用途 | 地址 |
|------|------|------|
| JPEXS Free Flash Decompiler | SWF 反编译 | https://github.com/jindrapetrik/jpexs-decompiler |
| swftools | SWF 命令行工具集 | http://www.swftools.org/ |
| RABCDAsm | ABC 字节码汇编 | https://github.com/CyberShadow/RABCDAsm |
| Ruffle | Flash 模拟器 | https://ruffle.rs/ |

---

**文档版本**：v1.0
**最后更新**：2026-10-04
