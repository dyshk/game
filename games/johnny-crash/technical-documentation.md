# Johnny Crash 技术文档

**游戏名称**：Johnny Crash: Stuntman Does Texas（大炮飞人之征服德州）
**类型**：Flash 游戏（SWF）
**技术方案**：Ruffle WebAssembly 模拟器 + 原版 SWF 二进制修改
**部署方式**：纯静态 HTML，可部署到任何虚拟主机（InfinityFree、虚拟主机等）

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
- ✅ 返回主页导航（游戏合集集成）

---

## 2. 文件结构

```
johnny-crash/
├── index.html                   # 主页面，包含 Ruffle 加载和游戏逻辑
├── johnny-crash.swf             # 原版游戏 SWF（正常能量消耗）
├── johnny-crash-hacked.swf      # 修改版 SWF（无限精力）
└── technical-documentation.md    # 本文档
```

### 文件说明

| 文件 | 大小 | 说明 |
|------|------|------|
| index.html | ~6 KB | 主页面，所有前端逻辑都在这里 |
| johnny-crash.swf | 907 KB | 原版游戏，CWS 格式（zlib 压缩） |
| johnny-crash-hacked.swf | 1079 KB | 修改版，FWS 格式（未压缩），能量消耗改为 0 |

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

#### 方式 A：自定义元素（简单）

```html
<script src="ruffle.js"></script>
<ruffle-player src="game.swf" width="550" height="400"></ruffle-player>
```

简单直接，但灵活性较差。

#### 方式 B：JavaScript API（本项目使用）

```js
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

```js
window.RufflePlayer.config = {
    autoplay: "on",           // 自动播放
    backgroundColor: "#000",  // 背景色
    quality: "high",          // 画质
    letterbox: "on"           // 保持宽高比（信箱模式）
};
```

### 3.4 为什么不能用 JS 模拟输入

最初尝试用 JavaScript 定时模拟鼠标按下/松开事件来实现"无限精力"，但失败了：

```js
// ❌ 这样做没用，Ruffle 会拦截
player.dispatchEvent(new MouseEvent('mousedown'));
```

**原因**：Ruffle 出于安全考虑，只信任**用户真实触发**的事件（`event.isTrusted === true`），JavaScript 合成的事件会被直接忽略。

**解决方案**：直接修改 SWF 文件的二进制数据，把能量消耗改成 0。

### 3.5 Ruffle 的兼容性

| 浏览器 | 支持情况 | 说明 |
|--------|---------|------|
| Chrome | ✅ 完美 | 桌面和移动端都支持 |
| Firefox | ✅ 完美 | 桌面和移动端都支持 |
| Safari | ✅ 支持 | 需较新版本 |
| Edge | ✅ 完美 | Chromium 内核 |
| IE | ❌ 不支持 | 不支持 WebAssembly |

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

```js
var cdnList = [
    'https://unpkg.com/@ruffle-rs/ruffle@0.6.0/ruffle.js',
    'https://cdn.jsdelivr.net/npm/@ruffle-rs/ruffle@0.6.0/ruffle.js',
    'https://cdnjs.cloudflare.com/ajax/libs/ruffle/0.6.0/ruffle.js',
    'https://cdn.jsdelivr.net/npm/@ruffle-rs/ruffle/ruffle.js'  // 最新版兜底
];
```

### 4.4 关键代码

```js
var currentCdn = 0;
var loadTimer = null;

function loadRuffle() {
    if (currentCdn >= cdnList.length) {
        showError('所有 CDN 源都无法访问');
        return;
    }

    var url = cdnList[currentCdn];
    var script = document.createElement('script');
    script.src = url;

    script.onerror = function() {
        currentCdn++;
        clearTimeout(loadTimer);
        loadRuffle();
    };

    script.onload = function() {
        clearTimeout(loadTimer);
        if (window.RufflePlayer && typeof window.RufflePlayer.newest === 'function') {
            startGame();
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

### 4.6 为什么不用本地自托管

| 方案 | 优点 | 缺点 | 我们选哪个 |
|------|------|------|-----------|
| 本地自托管 | 不依赖第三方，加载快 | WASM 14MB，免费主机传不了 | ❌ 不选 |
| 单个 CDN | 实现简单 | 那个 CDN 挂了就全挂了 | ❌ 不选 |
| 多 CDN 自动切换 | 容错率高，总有一个能用 | 代码稍微复杂一点 | ✅ 选这个 |

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

| 特征 | 说明 |
|------|------|
| 文件头是 CWS | 压缩过的 SWF |
| 包含 DefineBinaryData 标签 | 加密数据存放在这里 |
| 有 MochiAds 相关字符串 | mochiads、MochiBot、MochiCrypto 等 |
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

### 6.4 如何找到密钥

有两种方法：

**方法 1：反编译 Loader SWF**
用 JPEXS Free Flash Decompiler 打开 SWF，搜索 RC4 相关代码，找到密钥数组。

**方法 2：从二进制数据末尾提取**
MochiCrypt 的密钥通常放在 DefineBinaryData 数据的最后 32 字节。验证方式：用候选密钥解密前 1024 字节，看解密后的数据是不是 zlib 格式（0x78 0x9C 开头）。

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

需要找到：
1. 能量变量的名称
2. 能量初始值
3. 消耗能量的代码
4. 恢复能量的代码

#### 步骤 1：搜索关键词

在反编译后的代码中搜索：
```
energy, power, fuel, boost, stamina, break, fly, lift, rise, up
```

Johnny Crash 里能量变量是混淆过的，名字是 `§break§.§_-04§`（`§` 是 Flash 混淆器常用的字符）。

#### 步骤 2：查找数值

能量初始值是 212，飞行时每帧消耗 2.5。可以在 Double Pool 里搜索 2.5：

```python
import struct

def find_double_in_abc(abc_data, target_value):
    # 简化版：直接在二进制里搜 double 的 8 字节表示
    target_bytes = struct.pack('<d', target_value)
    return [i for i in range(len(abc_data)-7) 
            if abc_data[i:i+8] == target_bytes]
```

#### 步骤 3：定位消耗代码

找到 2.5 这个 double 值后，搜索哪些指令引用了它。

```as3
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

---

## 9. 二进制补丁方法

### 9.1 为什么用二进制补丁而不是重新编译

完全重新编译 SWF 需要：
1. 反编译得到 ActionScript 源码
2. 修改源码
3. 用 Flex SDK 重新编译
4. 重新加密（如果需要）

问题很多：
- 反编译的代码不一定能 100% 重新编译通过
- 混淆后的代码反编译质量很差
- 重新编译可能引入新的 bug

**二进制补丁更可靠**：直接在 SWF 的二进制数据里修改特定的字节，不需要重新编译。

### 9.2 修改 Double 值的方法

#### 原理

ActionScript 中的浮点数（Number 类型）是 64 位双精度浮点数（double），在 SWF 中以 **8 字节小端序**存储。

`2.5` 的 double 表示：
```
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
    old_bytes = struct.pack('<d', old_value)
    new_bytes = struct.pack('<d', new_value)
    
    count = 0
    result = bytearray(swf_data)
    offset = 0
    
    while True:
        pos = result.find(old_bytes, offset)
        if pos == -1:
            break
        
        result[pos:pos+8] = new_bytes
        count += 1
        offset = pos + 8
    
    return bytes(result), count
```

### 9.3 怎么确定只有一个 2.5？

在整个 SWF 中搜索 `2.5` 的 double 表示，如果只有一处，就可以安全替换。

Johnny Crash 里 `2.5` 的 double 值**只有一处**（就是能量消耗），所以直接替换不会误伤。

### 9.4 完整修改流程

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
│ ← 返回                       │
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

**功能：**
- 点击切换原版/无限精力版
- 状态保存在 localStorage
- 切换后自动刷新页面

**为什么要刷新页面？**
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
│  静态文件托管（GitHub Pages / InfinityFree）│
└─────────────────────────────────────────┘
```

| 层级 | 组成 | 干什么 |
|------|------|--------|
| 第 4 层 前端交互 | HTML + CSS + JS | 页面展示、按钮切换、CDN 加载、状态保存 |
| 第 3 层 模拟器 | Ruffle（CDN 加载） | 把 Flash 代码翻译成浏览器能跑的 WebAssembly |
| 第 2 层 游戏 SWF | 两个 SWF 文件 | 游戏本身的逻辑和画面 |
| 第 1 层 服务器 | 静态托管 | 提供文件下载 |

### 11.2 两个 SWF 文件的关系

| 对比项 | 原版 | 无限精力版 |
|--------|------|-----------|
| 文件名 | johnny-crash.swf | johnny-crash-hacked.swf |
| 格式 | CWS（zlib 压缩） | FWS（未压缩） |
| 能量消耗 | 2.5 / 每帧 | 0.0 / 每帧 |
| 其他逻辑 | 完全一致 | 完全一致 |

> **两个版本除了能量消耗这 8 个字节不一样，其他内容完全相同。** 修改量不到 0.001%。

### 11.3 无限精力的实现原理（完整链路）

```
用户点击 "⚡ 无限精力" 按钮
    ↓
① localStorage 保存状态（jc_infinite = "1"）
    ↓
② 页面刷新（window.location.reload()）
    ↓
③ 页面加载时读取 localStorage
    ↓
④ 根据状态选择 SWF 文件
    ↓
⑤ Ruffle 加载对应的 SWF 文件
    ↓
⑥ SWF 中能量消耗的代码
    原版：energy -= 2.5
    修改版：energy -= 0.0
    ↓
⑦ 游戏运行，能量条不减少 ✅
```

### 11.4 触摸操作是怎么实现的？

Ruffle 自动把触摸事件转换成鼠标事件：

```
手机触摸事件          Ruffle 转换         Flash 游戏接收
────────────          ────────          ────────────
touchstart    →      mousedown    →      按下鼠标
touchmove     →      mousemove    →      移动鼠标
touchend      →      mouseup      →      松开鼠标
```

所以这个游戏在手机上能直接玩，不需要额外加触摸控制代码。

> **注意**：这只适用于"点击/按住"类型的 Flash 游戏。如果是需要键盘、复杂鼠标操作的游戏，还是需要额外适配。

### 11.5 游戏运行时的完整数据流

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
游戏逻辑处理
  ↓
Ruffle 每帧渲染画面
  ↓
用户看到游戏在运行 ✅
```

---

## 12. 部署指南

### 12.1 部署要求

- 任何支持静态文件托管的服务器
- 不需要后端、不需要数据库
- 最大文件只有 1MB（SWF），远低于免费主机的 10MB 限制

### 12.2 部署步骤

1. 把 `johnny-crash/` 里的所有文件上传到网站子目录
2. 访问对应 URL 即可

### 12.3 MIME 类型配置

确保服务器正确配置 SWF 的 MIME 类型：

```apache
# .htaccess
AddType application/x-shockwave-flash .swf
AddType application/wasm .wasm
```

虽然 Ruffle 是从 CDN 加载的，但 WASM MIME 类型仍然重要——如果浏览器缓存了 CDN 的 WASM 文件，MIME 类型不对会影响流式编译。

### 12.4 缓存建议

```apache
# SWF 文件缓存 30 天（游戏不会频繁更新）
ExpiresByType application/x-shockwave-flash "access plus 30 days"

# HTML 不缓存（方便更新）
ExpiresByType text/html "access plus 0 seconds"
```

### 12.5 验证部署

上传完成后，检查：
1. ✅ 页面正常显示
2. ✅ Ruffle 模拟器加载成功（左下角显示 CDN 域名）
3. ✅ 游戏正常运行
4. ✅ 点击"无限精力"按钮切换正常
5. ✅ 无限精力模式下能量不消耗

---

## 13. 故障排查

### 13.1 页面空白/黑屏

**可能原因：**
1. Ruffle 加载失败
2. SWF 文件路径不对
3. 浏览器不支持 WebAssembly

**排查方法：**
- F12 看 Console 错误
- 看左下角的 CDN 信息，确认 Ruffle 是否加载成功
- Network 看 SWF 文件是否 404

### 13.2 游戏加载失败

**错误信息："Something went wrong loading the SWF"**

**可能原因：**
1. SWF 文件路径不对
2. SWF 文件损坏
3. 跨域问题（CORS）
4. MIME 类型不对

**解决方法：**
1. 确认 SWF 文件在正确的位置
2. 尝试直接访问 SWF 的 URL，看能不能下载
3. 如果是子目录部署，检查路径是否正确

### 13.3 无限精力不生效

**可能原因：**
1. 还在玩原版（没开启无限精力）
2. 缓存了旧版 SWF
3. 按钮点击没反应

**解决方法：**
1. 确认按钮是绿色的（active 状态）
2. 按 Ctrl+F5 强制刷新
3. 清浏览器缓存
4. 检查 `johnny-crash-hacked.swf` 文件是否存在

### 13.4 Ruffle 加载很慢

**可能原因：**
1. 当前 CDN 源访问慢
2. 网络不好

**解决方法：**
- 等一会儿，8 秒后会自动切换到下一个 CDN
- 多刷新几次，可能每次走的 CDN 不一样

### 13.5 手机上玩不了

**可能原因：**
1. 手机浏览器不支持 WebAssembly（旧手机）
2. 触摸操作不支持
3. 页面布局有问题

**说明：**
- 本游戏主要面向 PC 端
- 手机端可以打开，但操作体验不如 PC
- 如果需要更好的手机体验，需要额外开发触摸控制功能

---

## 14. 后续修改建议

### 14.1 如果想修改其他数值

方法和修改无限精力类似：
1. 找到要修改的数值
2. 在 SWF 二进制中搜索对应的数值
3. 替换成想要的值
4. 测试验证

**常见可修改的数值：**

| 数值 | 搜索方式 | 修改效果 |
|------|---------|---------|
| 初始能量 212 | 搜索整数 212 | 能量条初始更长/更短 |
| 老鹰恢复能量 | 搜索对应的数值 | 撞到老鹰恢复更多能量 |
| 重力 | 搜索对应的数值 | 角色下落更快/更慢 |
| 初始速度 | 搜索对应的数值 | 大炮发射速度更快 |

> **注意**：整数值可能有多处（比如初始值、显示值、重置值等），需要仔细区分。建议用 JPEXS 先反编译找到准确位置，再做二进制修改。

### 14.2 如果想解锁所有关卡

需要找到关卡解锁的判断逻辑，把判断条件改成永远为真。这比修改数值复杂，需要：
1. 反编译找到关卡相关的代码
2. 理解关卡解锁逻辑
3. 修改字节码中的条件跳转指令
4. 重新生成 SWF

### 14.3 如果想用本地 Ruffle（不依赖 CDN）

1. 从 https://ruffle.rs/#usage 下载 Self-Hosted 版本
2. 解压后把文件放到 `ruffle/` 文件夹
3. 修改 HTML，把 CDN 加载方式改成本地路径

> 注意：WASM 文件 14MB，需要确保主机支持上传这么大的文件。

---

## 15. Ruffle 内部原理深入

### 15.1 Ruffle 是什么做的

Ruffle 用 **Rust** 语言编写，通过 **wasm-bindgen** 编译成 WebAssembly，在浏览器中运行。

```
Rust 源码 (Ruffle)
    ↓
Rust 编译器 → .wasm + .js 胶水代码
    ↓
浏览器加载 ruffle.js
    ↓
JS 创建 WASM 实例
    ↓
WASM 里跑 Flash 模拟器
```

**为什么用 Rust？**
- **性能好**：Rust 编译成 WASM 后运行效率接近原生
- **内存安全**：Rust 的所有权系统避免了很多内存 bug
- **生态好**：wasm-bindgen 工具链成熟
- **社区活跃**：Rust 社区对 WASM 支持最好

### 15.2 Ruffle 的架构

Ruffle 不是一个简单的 SWF 播放器，它模拟了整个 Flash 运行时：

```
┌──────────────────────────────────────────────┐
│  ActionScript 虚拟机（AVM1 / AVM2）            │
│  - 解析 ABC 字节码                             │
│  - 执行 ActionScript 代码                      │
│  - 垃圾回收                                   │
├──────────────────────────────────────────────┤
│  渲染引擎                                     │
│  - 矢量图形渲染（Shape, Morph）                │
│  - 位图渲染（Bitmap）                          │
│  - 文本渲染（TextField）                       │
│  - 滤镜效果（模糊、发光等）                     │
├──────────────────────────────────────────────┤
│  音频引擎                                     │
│  - Sound 播放                                 │
│  - 音频流                                      │
├──────────────────────────────────────────────┤
│  输入系统                                     │
│  - 鼠标事件                                   │
│  - 键盘事件                                   │
│  - 触摸事件（自动转换）                        │
├──────────────────────────────────────────────┤
│  SWF 解析器                                   │
│  - 解析 SWF 文件头                             │
│  - 解析各个 Tag                                │
│  - 处理压缩（zlib / LZMA）                     │
└──────────────────────────────────────────────┘
```

### 15.3 AVM1 vs AVM2

Flash 有两代虚拟机：

| 特性 | AVM1 | AVM2 |
|------|------|------|
| 全称 | ActionScript Virtual Machine 1 | ActionScript Virtual Machine 2 |
| 支持语言 | ActionScript 1/2 | ActionScript 3 |
| 引入版本 | Flash 1 | Flash 9（2006） |
| 字节码格式 | 简单的栈式字节码 | ABC（ActionScript Bytecode） |
| 性能 | 低（解释执行） | 高（JIT 编译） |
| 类型系统 | 动态类型 | 静态 + 动态类型 |
| Ruffle 支持 | 较好 | 还在完善中 |

Johnny Crash 是 AVM2 的游戏（ActionScript 3）。

### 15.4 Ruffle 的渲染方式

Ruffle 有两种渲染后端：

**WebGL 渲染（默认）**
- 用 WebGL 加速渲染
- 性能好，特别是矢量图形多的场景
- 需要 WebGL 支持

**Canvas 渲染（兼容模式）**
- 用 Canvas 2D API 渲染
- 兼容性更好（老设备）
- 性能较差

Ruffle 会自动选择最佳的渲染后端。

### 15.5 Ruffle 与原生 Flash Player 的区别

| 特性 | 原生 Flash Player | Ruffle |
|------|------------------|--------|
| 性能 | 最快 | 接近原生（WASM） |
| AVM2 完整度 | 100% | 约 80-90%（还在完善） |
| 安全 | 很多漏洞 | 相对安全（沙箱 + Rust） |
| 视频支持 | 完整 | 部分支持 |
| Stage3D | 支持 | 支持中 |
| 移动端 | 不支持 | 支持 |

大多数老游戏 Ruffle 都能完美运行，但一些使用了高级特性的游戏可能有问题。

---

## 16. SWF 文件格式详解

### 16.1 文件头结构

SWF 文件头固定 8 字节：

```
字节偏移  长度  字段         说明
0        3     Signature    "FWS" 或 "CWS" 或 "ZWS"
3        1     Version      Flash 版本号
4        4     FileLength   文件总大小（小端序 uint32）
```

**签名类型：**

| 签名 | 含义 | 说明 |
|------|------|------|
| FWS | 未压缩 | Flash 1 及以上 |
| CWS | zlib 压缩 | Flash 6 及以上，前 8 字节不压缩 |
| ZWS | LZMA 压缩 | Flash 13 及以上 |

**版本号：**
- 版本号不是 Flash Player 的版本号
- 是 SWF 文件格式的版本号
- 例如 Flash MX 2004 对应 SWF 版本 7

### 16.2 帧大小（RECT 结构）

文件头之后是帧大小，用 SWF 的 RECT 结构存储：

```
RECT 结构：
  Nbits    5 bits     每边坐标的位数
  Xmin     Nbits bits  左边界（twips 单位）
  Xmax     Nbits bits  右边界
  Ymin     Nbits bits  上边界
  Ymax     Nbits bits  下边界
```

**twips 是什么？**
- 1 twip = 1/20 像素
- Flash 用 twips 作为内部单位，实现亚像素精度
- 例如 550×400 像素的舞台 = 11000×8000 twips

RECT 结构是**位对齐**的，不是字节对齐的，解析起来比较麻烦。

### 16.3 帧速率和帧数

帧大小之后：
```
字节偏移  长度  字段
0        2     FrameRate    帧速率（8.8 定点数）
2        2     FrameCount   总帧数
```

FrameRate 是 8.8 定点数（高 8 位整数部分，低 8 位小数部分）。例如：
- 0x0C00 = 12.0 fps
- 0x1800 = 24.0 fps

### 16.4 Tag 结构

帧头之后就是一系列 Tag，直到文件结束。

每个 Tag 的结构：

```
短 Tag 头（2 字节，标签长度 ≤ 62）：
┌──────────┬──────────┐
│ TagCode  │ Length   │
│ 10 bits  │ 6 bits   │
└──────────┴──────────┘

长 Tag 头（6 字节，标签长度 > 62）：
┌──────────┬──────────┬──────────────┐
│ TagCode  │ 0x3F     │ Length       │
│ 10 bits  │ 6 bits   │ 32 bits      │
└──────────┴──────────┴──────────────┘
```

**判断短 Tag 还是长 Tag：**
- 如果 Length 字段 = 0x3F（63），说明是长 Tag
- 后面还有 4 字节的真实长度

### 16.5 常见 Tag 类型

| Tag ID | 名称 | 作用 |
|--------|------|------|
| 0 | End | 文件结束 |
| 1 | ShowFrame | 显示帧（一帧的结束标记） |
| 2 | DefineShape | 定义矢量图形 |
| 9 | SetBackgroundColor | 设置背景色 |
| 14 | DefineSound | 定义声音 |
| 24 | PlaceObject2 | 放置对象到舞台 |
| 26 | RemoveObject2 | 从舞台移除对象 |
| 39 | DefineSprite | 定义影片剪辑 |
| 59 | DoInitAction | 初始化 ActionScript |
| 76 | SymbolClass | 符号类关联（AS3） |
| 87 | DoABC | ABC 字节码（AS3） |
| 82 | DefineSceneAndFrameLabelData | 场景和帧标签 |

完整的 Tag 类型有一百多种，这里只列了最常见的。

### 16.6 DefineBinaryData 标签

MochiCrypt 加密用的就是这个 Tag：

```
DefineBinaryData:
  ├─ Tag ID: 87
  ├─ Character ID: 2 字节
  ├─ Data: N 字节（任意二进制数据）
```

MochiCrypt 把加密的游戏数据存在这个 Tag 里，运行时解密后加载。

---

## 17. ABC 字节码深入

### 17.1 ABC 文件结构详解

ABC（ActionScript Bytecode）文件的完整结构：

```
┌──────────────────────────────┐
│  u16 minor_version           │  次版本号
│  u16 major_version           │  主版本号
├──────────────────────────────┤
│  int constant_pool_int_count │  整数字面量数量
│  s32[] constant_pool_int     │  整数字面量
├──────────────────────────────┤
│  uint constant_pool_uint_count│ 无符号整数数量
│  u32[] constant_pool_uint    │  无符号整数字面量
├──────────────────────────────┤
│  uint constant_pool_double_count │ 双精度浮点数量
│  f64[] constant_pool_double  │  双精度浮点字面量
├──────────────────────────────┤
│  uint constant_pool_string_count│ 字符串数量
│  string[] constant_pool_string│ 字符串字面量
├──────────────────────────────┤
│  uint constant_pool_namespace_count │ 命名空间数量
│  namespace[] namespaces      │  命名空间
├──────────────────────────────┤
│  uint constant_pool_multiname_count│ 多元名数量
│  multiname[] multinames      │  多元名（属性/方法名）
├──────────────────────────────┤
│  uint class_count            │  类定义数量
│  class_info[] classes        │  类定义
├──────────────────────────────┤
│  uint script_count           │  脚本数量
│  script_info[] scripts       │  脚本定义
├──────────────────────────────┤
│  uint method_count           │  方法数量
│  method_info[] methods       │  方法定义（包含字节码）
├──────────────────────────────┤
│  metadata_count              │  元数据数量
│  metadata[] metadata         │  元数据
└──────────────────────────────┘
```

### 17.2 常用操作码（Opcode）

ABC 字节码有 200+ 种操作码，以下是最常见的：

| 操作码 | 名称 | 作用 |
|--------|------|------|
| 0x01 | PushByte | 压入一个字节整数 |
| 0x02 | PushShort | 压入短整数 |
| 0x03 | PushTrue | 压入 true |
| 0x04 | PushFalse | 压入 false |
| 0x05 | PushNull | 压入 null |
| 0x06 | PushUndefined | 压入 undefined |
| 0x10 | PushDouble | 压入双精度浮点数 |
| 0x2F | GetLex | 获取类/全局变量 |
| 0x66 | GetProperty | 获取属性 |
| 0x68 | SetProperty | 设置属性 |
| 0x4F | Add | 加法 |
| 0x50 | Subtract | 减法 |
| 0x51 | Multiply | 乘法 |
| 0x52 | Divide | 除法 |
| 0x53 | Modulo | 取模 |
| 0x5A | Increment | 自增 +1 |
| 0x5B | Decrement | 自减 -1 |
| 0x5C | IncrementLocal | 局部变量自增 |
| 0x5D | DecrementLocal | 局部变量自减 |
| 0x12 | Jump | 无条件跳转 |
| 0x13 | IfTrue | 为真跳转 |
| 0x14 | IfFalse | 为假跳转 |
| 0x18 | IfEq | 相等跳转 |
| 0x19 | IfNe | 不等跳转 |
| 0x1A | IfLt | 小于跳转 |
| 0x1B | IfLe | 小于等于跳转 |
| 0x1C | IfGt | 大于跳转 |
| 0x1D | IfGe | 大于等于跳转 |
| 0x4D | CallProperty | 调用属性（方法） |
| 0x47 | ReturnValue | 返回值 |
| 0x48 | ReturnVoid | 返回空 |

### 17.3 字节码修改技巧

修改字节码比修改数值更灵活，但也更复杂。

**常见的修改类型：**

#### 修改条件跳转（绕过检查）
```
修改前：
  if (coins < price) goto fail;  // IfLt → fail 标签
  // 购买成功

修改后：
  if (true) goto success;        // Jump → success 标签
  // 购买成功
```

把条件跳转改成无条件跳转，直接跳过检查。

#### 修改函数返回值
```
修改前：
  function isUnlocked(plane) {
      return plane.unlocked;  // 返回实际状态
  }

修改后：
  function isUnlocked(plane) {
      return true;  // 永远返回 true
  }
```

把返回值改成常量，函数永远返回 true/false/特定值。

#### 修改比较逻辑
```
修改前：
  if (energy <= 0) gameOver();

修改后：
  if (false) gameOver();  // 条件永远不成立
```

把比较的一方改成常量，或者把比较指令改成 always-false。

### 17.4 怎么定位要修改的代码

**步骤 1：找到相关字符串**
- 搜索 UI 上的文字（"Energy"、"Game Over"、"Coins" 等）
- 字符串附近的代码通常就是相关逻辑

**步骤 2：找到相关数值**
- 在 Double Pool 或 Int Pool 里搜索已知的数值
- 看哪些指令引用了这个数值
- 往上追溯找到完整的函数

**步骤 3：理解代码逻辑**
- 反编译成 ActionScript 伪代码
- 理解函数的输入输出
- 确定修改的位置和方式

**步骤 4：做二进制修改**
- 计算字节偏移
- 修改对应的字节
- 注意不要改变代码长度（否则会破坏后面的跳转地址）

**如果需要改变代码长度：**
- 用 NOP（0x02 PushByte 0 之类的无副作用指令）填充多余空间
- 或者重新编译整个 SWF（更麻烦，但更灵活）

---

## 18. 反编译实战指南

### 18.1 JPEXS 使用技巧

JPEXS Free Flash Decompiler 是最常用的 SWF 反编译工具。

**常用功能：**
- **Scripts 面板**：查看所有 ActionScript 代码
- **BinaryData 面板**：查看嵌入的二进制数据（MochiCrypt 加密数据在这里）
- **Hex 视图**：直接查看和编辑二进制
- **Search**：全局搜索字符串、数值、方法名
- **Traits**：查看类的属性和方法

**高级技巧：**
- 可以直接修改反编译后的代码，然后保存
- 但不建议直接改源码（重新编译可能出问题）
- 更可靠的方式是直接改字节码
- JPEXS 的 P-Code 视图可以直接编辑字节码

### 18.2 识别加密的 SWF

**判断是不是 MochiCrypt：**
1. 用 JPEXS 打开 SWF
2. 看有没有 DefineBinaryData 标签
3. 搜索 "mochi"、"crypt"、"RC4" 等关键词
4. 看 Loader 代码里有没有解密逻辑

**判断是不是其他加密：**
- **SWFEncrypt**：常见的商业加密，特征是代码混淆严重
- **DComSoft**：另一种商业加密
- **自制加密**：五花八门，需要具体分析

### 18.3 解混淆技巧

混淆过的代码很难读，有一些技巧：

**技巧 1：重命名变量**
- 混淆后的变量名通常是 `_loc1_`、`_loc2_`、`§_-04§` 之类
- 理解代码功能后，可以手动重命名
- JPEXS 支持重命名标识符

**技巧 2：找关键函数**
- 先找容易识别的函数（比如构造函数、事件处理函数）
- 从这些函数往外扩散
- 逐步理解整个系统

**技巧 3：用调试器**
- Ruffle 有调试功能（开发版）
- 可以下断点、单步执行
- 看变量实时变化

### 18.4 修改后验证

修改 SWF 后，一定要验证：

**验证清单：**
1. ✅ SWF 能不能加载
2. ✅ 游戏能不能进主菜单
3. ✅ 修改的功能是否生效
4. ✅ 有没有引入新的 bug
5. ✅ 游戏能不能通关（或正常玩一段时间）
6. ✅ 性能有没有下降

**回归测试很重要**，因为二进制修改容易误伤。

---

## 19. 附录：更多工具和资源

### 19.1 SWF 工具列表

| 工具 | 类型 | 平台 | 开源 | 说明 |
|------|------|------|------|------|
| JPEXS FFDec | 反编译/编辑 | Win/Mac/Linux | ✅ | 最流行的 SWF 反编译工具 |
| swftools | 命令行工具集 | Win/Linux | ✅ | swfdump, swfextract 等 |
| RABCDAsm | ABC 汇编/反汇编 | Win/Linux | ✅ | 低级 ABC 操作 |
| As3sor | AS3 反编译器 | 跨平台 | ✅ | 另一个 AS3 反编译器 |
| Flash Decompiler Trillix | 商业反编译 | Win/Mac | ❌ | 商业软件 |
| SWiX | SWF 编辑器 | Win | 免费版 | XML 格式编辑 SWF |

### 19.2 Ruffle 开发资源

| 资源 | 地址 |
|------|------|
| 官网 | https://ruffle.rs/ |
| GitHub | https://github.com/ruffle-rs/ruffle |
| 文档 | https://github.com/ruffle-rs/ruffle/wiki |
| 在线演示 | https://ruffle.rs/demo/ |
| Discord 社区 | https://discord.gg/ruffle |

### 19.3 Flash 逆向资源

| 资源 | 说明 |
|------|------|
| SWF 文件格式规范 | Adobe 官方文档（已归档，但能找到存档） |
| AVM2 规范 | Adobe 官方的 ActionScript 虚拟机规范 |
| SWFExplorer | 分析 SWF 内部结构的工具 |
| Flash 逆向论坛 | 相关社区和讨论 |

### 19.4 常用命令速查

**用 swfdump 查看 SWF 信息：**
```bash
swfdump -D file.swf    # 反汇编
swfdump -t file.swf    # 列出所有标签
swfdump -a file.swf    # 列出所有 ActionScript
```

**用 ffdec 命令行反编译：**
```bash
ffdec.jar -export script output_dir file.swf
```

---

---

## 20. Flash 安全模型深入

### 20.1 Flash Player 沙箱机制

Flash Player 有严格的安全模型，核心是**沙箱（Sandbox）**机制。

**沙箱类型**：

| 沙箱类型 | 说明 | 权限 |
|---------|------|------|
| 本地文件沙箱 | 直接打开本地 SWF（file://） | 最低，不能访问网络 |
| 远程沙箱 | 从网络加载的 SWF（http://） | 中等，只能访问同域资源 |
| 受信任本地沙箱 | 用户手动信任的本地 SWF | 较高，可以访问网络 |
| AIR 沙箱 | Adobe AIR 应用 | 最高，接近本地程序 |

**为什么 Ruffle 不需要关心这些？**
- Ruffle 运行在浏览器的 JS 环境中
- 受浏览器的同源策略限制，而不是 Flash 的沙箱
- Ruffle 模拟了 Flash 的 API，但安全模型是浏览器的

### 20.2 安全域（Security Domain）

每个 SWF 属于一个安全域，由加载它的域名决定：

```
example.com 域的 SWF ──┐
                       ├─ 同一个安全域，可以互相访问
example.com 域的 SWF ──┘

other.com 域的 SWF ──── 不同安全域，默认不能访问
```

**跨域访问的方式**：
1. `Security.allowDomain("other.com")` — 允许指定域访问
2. `Security.allowInsecureDomain()` — 允许非 HTTPS 域访问
3. 跨域策略文件（crossdomain.xml）— 服务端授权

### 20.3 跨域策略文件（crossdomain.xml）

如果 SWF 要请求另一个域名的数据，目标服务器需要放一个 `crossdomain.xml`：

```xml
<?xml version="1.0"?>
<!DOCTYPE cross-domain-policy SYSTEM "http://www.adobe.com/xml/dtds/cross-domain-policy.dtd">
<cross-domain-policy>
    <allow-access-from domain="*" />
    <allow-http-request-headers-from domain="*" headers="*"/>
</cross-domain-policy>
```

这类似于 CORS，但 Flash 有自己的机制。

**和浏览器 CORS 的区别**：
- Flash 用 crossdomain.xml，浏览器用 CORS 头
- Flash 检查更严格（默认拒绝）
- Ruffle 目前是通过浏览器 fetch 请求的，所以受 CORS 限制，而不是 crossdomain.xml

### 20.4 LocalConnection 安全

LocalConnection 允许同一个浏览器中的多个 SWF 互相通信。

安全机制：
- 发送方和接收方必须在同一个安全域
- 可以用 `allowDomain()` 授权其他域
- 通信是本地的，不经过网络

### 20.5 SharedObject 安全

SharedObject（Flash 的"本地 Cookie"）也有安全限制：
- 按域隔离，不同域不能互相访问
- 默认大小限制 100KB（可以用户调整）
- 用户可以随时清除

Ruffle 用 localStorage 模拟 SharedObject，受浏览器的 localStorage 限制。

### 20.6 为什么 Flash 最终被淘汰

安全是 Flash 被淘汰的主要原因之一：

1. **漏洞太多**：Flash Player 历史上有大量安全漏洞
2. **沙箱逃逸**：经常出现绕过沙箱的漏洞
3. **更新缓慢**：Adobe 的补丁响应慢
4. **封闭生态**：不是开放标准，受 Adobe 控制

HTML5 开放、安全、原生支持，所以逐渐取代了 Flash。

---

## 21. AVM2 虚拟机原理详解

### 21.1 AVM2 是什么

AVM2（ActionScript Virtual Machine 2）是 Flash Player 中执行 ActionScript 3 的虚拟机。

- AVM1：执行 ActionScript 1/2（解释执行）
- AVM2：执行 ActionScript 3（JIT 编译，性能好很多）

AVM2 是基于 Adobe 的 Tamarin 项目，开源的，Mozilla 曾经想用它做 JavaScript 引擎（后来放弃了）。

### 21.2 AVM2 架构

```
┌─────────────────────────────────────────────┐
│              AVM2 虚拟机                     │
│                                             │
│  ┌───────────┐    ┌──────────────────┐     │
│  │ 验证器     │    │   JIT 编译器      │     │
│  │ (Verifier) │    │ (Just-In-Time)   │     │
│  └─────┬─────┘    └────────┬─────────┘     │
│        │                   │               │
│        ▼                   ▼               │
│  ┌──────────────────────────────────┐      │
│  │        解释器 / 执行引擎           │      │
│  └──────────────────────────────────┘      │
│        │                                   │
│        ▼                                   │
│  ┌──────────────────────────────────┐      │
│  │        内存管理 / GC              │      │
│  └──────────────────────────────────┘      │
└─────────────────────────────────────────────┘
```

### 21.3 ABC 文件执行流程

1. **加载**：读取 ABC 字节码
2. **验证**：验证字节码的正确性（类型安全、栈平衡等）
3. **解析**：解析常量池、方法、类等结构
4. **初始化**：执行类初始化代码（static initializer）
5. **执行**：解释执行或 JIT 编译执行

**验证器的作用**：
- 确保字节码是类型安全的
- 确保操作数栈不会溢出/下溢
- 确保不会跳转到非法地址
- 类似于 JVM 的字节码验证

### 21.4 操作数栈（Operand Stack）

AVM2 是基于栈的虚拟机，大多数操作都在操作数栈上进行。

```
操作数栈：
┌───────┐
│  123  │  ← 栈顶 (sp)
├───────┤
│  "hi" │
├───────┤
│  obj  │
└───────┘
```

**示例：加法操作**
```
操作前栈：[a, b]   （b 在栈顶）
  ↓
执行 Add 指令：弹出 b 和 a，计算 a + b，压入结果
  ↓
操作后栈：[a+b]
```

**为什么用栈而不是寄存器？**
- 栈式虚拟机代码更紧凑
- 更容易实现解释器
- JIT 编译时可以优化成寄存器版

### 21.5 作用域链（Scope Chain）

AVM2 用作用域链来查找变量：

```
当前函数的作用域
    ↓
父函数的作用域
    ↓
全局作用域
```

`findproperty` 指令就是沿着作用域链往上找属性。

这和 JavaScript 的作用域链非常相似（毕竟都是 ECMAScript 系）。

### 21.6 类与继承

AVM2 是面向对象的，支持类和继承。

**类的结构**：
- `instance_info`：实例属性和方法
- `class_info`：静态属性和方法
- `trait`：特征（属性、方法、getter/setter 等）

**继承机制**：
- 单继承（和 Java/C# 一样）
- 支持接口
- 支持虚方法（virtual method）
- 方法调用用 `callproperty` 或 `callsuper`

### 21.7 垃圾回收（GC）

AVM2 使用**标记-清除（Mark-Sweep）**垃圾回收器：

1. **标记阶段**：从根对象（全局对象、栈上对象等）出发，标记所有可达的对象
2. **清除阶段**：回收所有未标记的对象

**特点**：
- 分代回收（新生代 + 老年代）
- 增量回收（不会一次性卡很久）
- 但 GC 停顿还是存在（老版本 Flash 很明显）

### 21.8 JIT 编译

AVM2 有 JIT（Just-In-Time）编译器，可以把热点代码编译成本地机器码：

```
字节码 → 中间表示（IR）→ 优化 → 本地机器码
```

**触发条件**：
- 函数被调用很多次
- 循环执行很多次

**优化手段**：
- 类型特化（根据实际类型生成优化代码）
- 内联（把小函数展开）
- 死代码消除
- 寄存器分配

这也是 AS3 比 AS2 快很多的原因（AVM1 只有解释器）。

---

## 22. SWF 加壳与脱壳技术

### 22.1 什么是加壳

加壳（Packing/Protecting）是指对 SWF 进行加密或混淆，防止被反编译。

**常见加壳工具**：
- MochiCrypt（本游戏用的就是这个）
- SecureSWF
- SWF Encrypt
- Amayeta SWF Encrypt
- DComSoft SWF Protector

### 22.2 加壳的原理

大多数 SWF 加壳工具的原理都差不多：

```
原始 SWF → 加密 → 加密数据
                    ↓
             包装成新的 SWF
             （包含解密代码 + 加密数据）
```

运行时：
1. 先运行外壳的解密代码
2. 解密原始 SWF
3. 用 `loadBytes()` 加载解密后的 SWF

**本质**：外壳 + 加密的内部 SWF。

### 22.3 为什么加壳是可以破解的

因为 Flash Player 最终要执行原始字节码，所以：
- 密钥一定在 SWF 内部（不然怎么解密？）
- 解密算法也一定在 SWF 内部
- 只要找到解密函数，就能还原原始 SWF

**加壳只能防小白，防不了高手**。这和 Windows 程序加壳是一个道理。

### 22.4 脱壳方法分类

| 方法 | 原理 | 难度 | 通用性 |
|------|------|------|--------|
| 静态分析 | 分析加密算法，写解密工具 | ★★★★ | 低（每种壳不同） |
| 内存转储 | 运行后从内存中 dump 解密后的 SWF | ★★★ | 高（通用） |
| 动态调试 | 下断点，在解密后截获数据 | ★★★★ | 中 |
| 已知漏洞 | 利用加壳工具的漏洞 | ★★ | 低 |

### 22.5 内存转储法（通用脱壳思路）

最通用的脱壳方法——内存转储（Memory Dump）：

**原理**：SWF 被解密后一定会加载到内存中，只要能在那个时刻把内存里的 SWF 导出来就行。

**Flash Player 时代的方法**：
1. 用 Cheat Engine 搜内存
2. 找 SWF 的签名（FWS/CWS/ZWS）
3. 找到后导出整个文件

**Ruffle 时代的方法**：
1. Ruffle 是 JS 实现的，可以在源码里加断点
2. 在 `loadBytes` 函数里拦截数据
3. 把数据导出来

**更简单的方法**：
1. 用 FFDec 打开加壳的 SWF
2. FFDec 会自动脱很多常见的壳
3. 如果不行，就用更高级的工具

### 22.6 MochiCrypt 详细分析

本游戏用的 MochiCrypt 是比较简单的壳：

**加密流程**：
1. 原始 SWF 用 RC4 加密
2. 密钥嵌入在外壳中
3. 外壳加载时解密，然后 `loadBytes`

**解密的关键**：
- 找到密钥字符串
- 找到加密数据的位置和长度
- 用 RC4 解密

**为什么容易破解？**
- RC4 算法是固定的，只要找到密钥就行
- 密钥在 AS3 代码里，反编译就能看到
- 甚至可以直接搜字符串，密钥通常是可打印字符

### 22.7 混淆（Obfuscation）

混淆和加壳不同，混淆不加密，只是把代码变难看：

- 变量名改成无意义的（`a`, `b`, `_0x1234`）
- 字符串加密（运行时解密）
- 控制流平坦化（把正常的 if/else 改成 switch）
- 插入垃圾代码

**常见混淆工具**：
- secureSWF
- obfuscator（开源的 AS3 混淆器）
- 各种在线混淆工具

**去混淆的方法**：
- 重命名（把无意义的名字改成有意义的）
- 字符串解密（写脚本还原）
- 控制流还原（比较难，通常手动分析）

混淆比加壳更难处理，因为代码逻辑还在，只是难看。加壳解密后代码是清晰的。

---

## 23. 高级逆向技巧

### 23.1 字符串搜索法

最简单但非常有效的方法——搜字符串。

**思路**：游戏中一定有一些独特的字符串：
- UI 文字（"Game Over"、"Score" 等）
- 错误提示
- 变量名（如果没混淆的话）
- URL（如果有联网功能）

找到字符串后，找哪里引用了它，就能定位到相关代码。

**例子**：想找金币相关的代码
1. 搜 "coin" 或 "gold" 或 "money"
2. 找到后，看哪些函数引用了这个字符串
3. 顺着找上去，就能找到金币逻辑

### 23.2 特征码定位

如果没有字符串（或者字符串是加密的），可以用特征码。

**特征码**：一段独特的字节序列，用来定位特定的代码。

**怎么找特征码？**
1. 先找到一个你认识的函数
2. 看它的字节码，找一段比较独特的
3. 用这段字节码去搜，就能找到相同的函数

**适用场景**：
- 版本更新后，函数地址变了，但代码没变
- 同系列游戏的相同功能
- 加壳后，内部代码特征不变

### 23.3 动态调试法

静态分析搞不定的时候，就用动态调试。

**Flash 时代的调试工具**：
- Flash Builder / FlashDevelop（官方调试器）
- fdb（命令行调试器）
- Cheat Engine（内存修改）
- 各种内存搜索工具

**Ruffle 时代的调试**：
- Chrome DevTools 直接调试 JS
- 可以在 Ruffle 的源码里下断点
- 可以查看 WASM 内存

**调试技巧**：
1. 在关键函数入口下断点
2. 查看参数和返回值
3. 单步执行，看每一步做了什么
4. 修改内存，验证猜想

### 23.4 二分法修改

不确定哪段代码是干什么的？用二分法。

**方法**：
1. 把目标代码改成空操作（NOP）
2. 运行看看有什么变化
3. 根据变化推断代码的作用

**例子**：想找能量消耗的代码
1. 找到几个可疑的数值减少操作
2. 挨个改成 0（不减）
3. 看哪个改了之后能量不掉
4. 那个就是能量消耗代码

这是逆向工程中最常用的"笨办法但有效"。

### 23.5 帧标签定位法

很多 Flash 游戏用帧（Frame）来管理场景：
- 第 1 帧：主菜单
- 第 2 帧：游戏画面
- 第 3 帧：游戏结束

**方法**：
1. 先用 SWF 工具看有哪些帧标签
2. 帧标签通常是有意义的（如 "game", "menu", "over"）
3. 根据帧标签定位对应场景的代码

### 23.6 资源反推法

游戏的资源（图片、声音）通常和代码是对应的。

**方法**：
1. 导出 SWF 中的所有图片
2. 找到你感兴趣的那张图（比如金币的图片）
3. 看它的导出类名（Export Class Name）
4. 搜这个类名，就能找到使用它的代码

**例子**：
- 找到金币图片，类名是 `CoinMovieClip`
- 搜 `CoinMovieClip`，找到所有创建金币的地方
- 顺着找到金币的逻辑

### 23.7 修改验证技巧

修改后怎么验证改对了？

1. **功能测试**：修改的功能是否生效
2. **回归测试**：其他功能是否正常
3. **边界测试**：极端情况会不会崩
4. **性能测试**：有没有变卡

**二进制修改的常见坑**：
- 修改了长度，导致后面的偏移全错（所以尽量用等长替换）
- 修改了指令，但操作数栈不平衡（AVM2 验证会报错）
- 改错了地方，影响了其他功能
- 加壳的 SWF，改了外壳没用（要改内部的）

---

## 24. 附录：Flash 技术演进史

### 24.1 Flash 的诞生与发展

| 年份 | 事件 |
|------|------|
| 1995 | FutureWave 发布 FutureSplash Animator |
| 1996 | Macromedia 收购，改名 Flash 1.0 |
| 1998 | Flash 3，加入 ActionScript 1 |
| 2000 | Flash 5，ActionScript 更完善 |
| 2002 | Macromedia Flash MX，AS1 成熟 |
| 2003 | Flash MX 2004，ActionScript 2 |
| 2005 | Adobe 收购 Macromedia |
| 2006 | Flash 8，滤镜、混合模式 |
| 2007 | Flash CS3，ActionScript 3 + AVM2 |
| 2008 | Flash Player 10，3D、Pixel Bender |
| 2010 | Flash Player 10.3，Stage Video |
| 2011 | Flash Player 11，Stage3D（GPU 加速 3D） |
| 2012 | Adobe 宣布停止移动版 Flash 开发 |
| 2017 | Adobe 宣布 2020 年停止支持 Flash |
| 2020 | Flash Player 正式停止服务 |
| 2021 | Ruffle 等模拟器继续维护 Flash 生态 |

### 24.2 ActionScript 的演进

**ActionScript 1**（1998）：
- 基于 ECMAScript，但很不标准
- 解释执行，速度慢
- 面向对象是原型式的
- 类型松散

**ActionScript 2**（2003）：
- 加入了类型注解（编译时检查）
- 加入了 class、extends、interface 等关键字
- 但底层还是 AVM1，运行时和 AS1 一样
- 语法糖性质大于实质改进

**ActionScript 3**（2007）：
- 全新的虚拟机 AVM2
- JIT 编译，性能提升 10 倍
- 完全的面向对象（类、继承、接口、包）
- 强类型（运行时也检查）
- E4X（XML 原生支持）
- 基于 Tamarin 项目（Adobe 捐给 Mozilla 的）

### 24.3 Flash 的技术遗产

Flash 虽然死了，但它留下了很多技术遗产：

1. **Web 动画**：Flash 证明了 Web 可以做丰富的动画和交互
2. **网页游戏**：Flash 时代催生了大量网页游戏，奠定了网页游戏市场
3. **视频播放**：在 HTML5 video 之前，Flash 是网页视频的标准
4. **RIA 概念**：富互联网应用（Rich Internet Application）的先驱
5. **ActionScript**：影响了后来的 JavaScript 演进（AS3 很像现代 JS）
6. **SVG/Canvas/WebGL**：这些技术都从 Flash 那里借鉴了很多

### 24.4 为什么 Ruffle 重要

Flash 停止支持后，大量的 Flash 内容面临消失的风险：
- 数以万计的 Flash 游戏
- 大量的 Flash 动画和教程
- 很多互动网站和艺术作品

这些都是互联网文化遗产，如果不能运行就永远消失了。

Ruffle 的意义：
- **保存历史**：让经典 Flash 内容能够继续运行
- **无需插件**：用 WebAssembly 实现，浏览器原生支持
- **开源免费**：社区驱动，持续发展
- **安全**：在浏览器沙箱中运行，比 Flash Player 安全得多

可以把 Ruffle 理解为 Flash 世界的"文物保护技术"。

---

## 25. 完整复刻部署指南（从零到上线不出错）

> 本章手把手教你从零搭建一个基于 Ruffle + 修改版 SWF 的 Flash 游戏页面，每步都有验证点。

### 25.1 前置准备

**你需要准备的东西**：

| 项目 | 要求 | 说明 |
|------|------|------|
| 原版 SWF 文件 | `.swf` 格式 | 你想要运行的 Flash 游戏 |
| JPEXS FFDec | 反编译工具 | 用来分析和修改 SWF |
| Python 3 | 运行解密脚本 | 如果 SWF 加了壳需要用 |
| 静态文件托管 | 任意 | GitHub Pages / InfinityFree / Vercel 等 |
| 文本编辑器 | VS Code / 记事本 | 写 HTML 和 JS |

**下载 JPEXS FFDec**：
- 官网：https://github.com/jindrapetrik/jpexs-decompiler
- 需要 Java 8 或更高版本
- 下载 `.jar` 文件或安装包

### 25.2 第一步：确认 SWF 能用

在修改之前，先确认原版 SWF 是好的。

**方法 1：用 Ruffle 在线测试**
- 访问 https://ruffle.rs/demo/
- 把 SWF 文件拖进去
- 看能不能正常运行

**方法 2：本地测试**
```bash
# 建个临时目录
mkdir test-swf
cd test-swf

# 把 SWF 放进来

# 启动 HTTP 服务器
python -m http.server 8080
```
然后浏览器打开，用 Ruffle 加载（需要自己写个简单的 HTML，见下一步）。

**✅ 验证点 1**：
- SWF 能正常加载
- 游戏能正常玩
- 没有报错

### 25.3 第二步：写一个最小的 Ruffle 页面

创建 `index.html`：

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Johnny Crash</title>
    <script src="https://unpkg.com/@ruffle-rs/ruffle@0.6.0/ruffle.js"></script>
    <style>
        body {
            margin: 0;
            background: #1a1a2e;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        #game-container {
            width: 550px;
            height: 400px;
            background: #000;
        }
    </style>
</head>
<body>
    <div id="game-container"></div>

    <script>
        window.addEventListener('load', function() {
            var ruffle = window.RufflePlayer.newest();
            var player = ruffle.createPlayer();
            var container = document.getElementById('game-container');
            container.appendChild(player);
            
            player.load('johnny-crash.swf').then(function() {
                console.log('游戏加载成功');
            }).catch(function(err) {
                console.error('加载失败:', err);
                alert('游戏加载失败: ' + err.message);
            });
        });
    </script>
</body>
</html>
```

**文件结构**：
```
test-swf/
├── index.html
└── johnny-crash.swf
```

**✅ 验证点 2**：
- `python -m http.server 8080` 启动
- 访问 `http://localhost:8080/`
- 看到游戏画面
- F12 控制台显示"游戏加载成功"

> ⚠️ **常见坑**：不要用 `file://` 直接打开，必须用 HTTP 服务器。Ruffle 从 CDN 加载，需要网络。

### 25.4 第三步：分析 SWF，找到要修改的逻辑

**目标**：找到能量消耗的代码位置。

**用 JPEXS FFDec 打开 SWF**：
1. 启动 FFDec（`ffdec.jar` 或快捷方式）
2. File → Open，选择 SWF 文件
3. 等待加载完成

**搜索关键字**：
- 在左侧面板展开 `Scripts`
- 点击搜索图标（或 Ctrl+F）
- 搜索 "energy"、"stamina"、"power" 等和能量相关的词
- 也可以搜 "health"、"hp"、"life"

**找到能量变量后**：
- 看它在哪里被减少（减法操作）
- 记下类名和函数名
- 这就是我们要修改的目标

**✅ 验证点 3**：
- 找到了能量变量的定义
- 找到了能量减少的代码位置
- 能理解这段代码的逻辑

> 💡 **搜索技巧**：如果英文搜不到，试试中文游戏里的中文文字。搜不到代码就先搜资源（图片、文字），从资源反推代码。

### 25.5 第四步：解密（如果 SWF 加了壳）

很多 Flash 游戏加了 MochiCrypt 等壳，FFDec 直接打开可能看不到清晰的代码。

**判断是否加壳**：
- FFDec 打开后脚本很少（只有一两个）
- 脚本里有 `loadBytes`、`ByteArray`、加密相关的代码
- 有 `mochi`、`crypt`、`encrypt` 等字样

**MochiCrypt 解密步骤**：

1. 在 FFDec 中找到外壳脚本
2. 找到 RC4 密钥（通常是一个字符串常量）
3. 找到加密数据（通常是嵌入的二进制数据）
4. 用 Python 解密：

```python
# mochicrypt_decrypt.py
import sys

def rc4_decrypt(key, data):
    """RC4 解密算法"""
    S = list(range(256))
    j = 0
    for i in range(256):
        j = (j + S[i] + key[i % len(key)]) % 256
        S[i], S[j] = S[j], S[i]
    
    i = j = 0
    result = bytearray()
    for byte in data:
        i = (i + 1) % 256
        j = (j + S[i]) % 256
        S[i], S[j] = S[j], S[i]
        result.append(byte ^ S[(S[i] + S[j]) % 256])
    
    return bytes(result)

# 使用方法：
# 1. 从 SWF 中提取加密数据（二进制）
# 2. 找到密钥字符串
# 3. 解密
key = b"你的密钥字符串"
with open("encrypted_data.bin", "rb") as f:
    encrypted = f.read()

decrypted = rc4_decrypt(key, encrypted)

with open("decrypted.swf", "wb") as f:
    f.write(decrypted)

print("解密完成，输出 decrypted.swf")
```

**✅ 验证点 4**：
- 解密后的文件能用 FFDec 打开
- 能看到完整的脚本和资源
- 能找到能量消耗的代码

> 💡 **更简单的方法**：很多加壳的 SWF，FFDec 会自动识别并解密。如果 FFDec 打开后直接能看到清晰代码，就不用手动解密。

### 25.6 第五步：修改 SWF（实现无限精力）

**方法：用 FFDec 直接修改 ActionScript**

1. 在 FFDec 中找到能量消耗的函数
2. 右键 → Edit ActionScript
3. 把能量减少的操作改成不减
4. 保存修改

**示例**：

假设找到的代码是这样的：
```actionscript
public function useEnergy(amount:Number):void {
    _energy -= amount;  // 减去消耗的能量
    if (_energy < 0) {
        _energy = 0;
        gameOver();
    }
}
```

改成这样：
```actionscript
public function useEnergy(amount:Number):void {
    // 能量不减（无限精力）
    _energy = 100;  // 保持满能量
    // 或者直接什么都不做
}
```

或者找到能量减少的具体行，把减去的量改成 0。

**保存修改后的 SWF**：
1. File → Save As
2. 保存为 `johnny-crash-hacked.swf`
3. 等待保存完成

**✅ 验证点 5**：
- 修改后的 SWF 能正常加载
- 游戏中能量不会减少
- 其他功能正常（没有引入 bug）

> ⚠️ **常见坑**：
> 1. 改完后游戏崩了 → 改错了地方，撤销重试
> 2. 改完没效果 → 改的不是真正生效的代码，再找找
> 3. FFDec 保存失败 → 可能是 SWF 有保护，换个方法

### 25.7 第六步：CDN 多源自动切换

单个 CDN 可能不稳定，做多源自动切换更保险。

**完整的 Ruffle 加载代码**：

```js
var cdnList = [
    'https://unpkg.com/@ruffle-rs/ruffle@0.6.0/ruffle.js',
    'https://cdn.jsdelivr.net/npm/@ruffle-rs/ruffle@0.6.0/ruffle.js',
    'https://cdnjs.cloudflare.com/ajax/libs/ruffle/0.6.0/ruffle.js',
    'https://cdn.jsdelivr.net/npm/@ruffle-rs/ruffle/ruffle.js'  // 最新版兜底
];

var currentCdn = 0;
var loadTimer = null;
var gameStarted = false;

function loadRuffle() {
    if (currentCdn >= cdnList.length) {
        showError('所有 CDN 源都无法访问，请检查网络连接');
        return;
    }

    var url = cdnList[currentCdn];
    var script = document.createElement('script');
    script.src = url;

    script.onerror = function() {
        currentCdn++;
        clearTimeout(loadTimer);
        loadRuffle();
    };

    script.onload = function() {
        clearTimeout(loadTimer);
        if (window.RufflePlayer && typeof window.RufflePlayer.newest === 'function') {
            startGame();
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

function startGame() {
    if (gameStarted) return;
    gameStarted = true;

    var ruffle = window.RufflePlayer.newest();
    var player = ruffle.createPlayer();
    var container = document.getElementById('game-container');
    container.appendChild(player);

    // 选择 SWF（原版/修改版）
    var swfFile = useHacked ? 'johnny-crash-hacked.swf' : 'johnny-crash.swf';
    
    player.load(swfFile).then(function() {
        console.log('游戏加载成功');
    }).catch(function(err) {
        showError('游戏加载失败: ' + err.message);
    });
}

// 页面加载完成后开始
window.addEventListener('load', loadRuffle);
```

**✅ 验证点 6**：
- 正常网络下，游戏能在 8 秒内加载
- 把第一个 CDN 改成无效地址，测试能否自动切换到第二个
- 控制台能看到加载过程

### 25.8 第七步：添加切换按钮（原版/修改版）

在页面上加一个按钮，可以切换原版和修改版：

```html
<button id="toggle-btn">切换到原版</button>
```

```js
var useHacked = true;  // 默认用修改版
var toggleBtn = document.getElementById('toggle-btn');

toggleBtn.addEventListener('click', function() {
    useHacked = !useHacked;
    toggleBtn.textContent = useHacked ? '切换到原版' : '切换到修改版';
    
    // 重新加载游戏
    var container = document.getElementById('game-container');
    container.innerHTML = '';
    gameStarted = false;
    startGame();
});
```

**✅ 验证点 7**：
- 点击按钮能切换
- 切换后游戏重新加载
- 修改版能量不减，原版能量正常消耗

### 25.9 第八步：本地完整测试

**测试清单**：

- [ ] 页面能正常打开
- [ ] Ruffle 能正常加载
- [ ] 原版 SWF 能正常运行
- [ ] 修改版 SWF 能正常运行
- [ ] 无限精力功能生效
- [ ] 切换按钮工作正常
- [ ] 游戏可以正常玩到结束
- [ ] 没有 JS 报错
- [ ] 刷新页面功能正常

### 25.10 第九步：部署到服务器

**部署方式**：纯静态文件，任何静态托管都行。

**上传文件清单**：

| 文件 | 必传 | 说明 |
|------|------|------|
| `index.html` | ✅ | 主页面 |
| `johnny-crash.swf` | ✅ | 原版 SWF |
| `johnny-crash-hacked.swf` | ✅ | 修改版 SWF |

**不需要上传**：
- Ruffle 的文件（CDN 加载）
- 字体文件（系统字体或 CDN）

**部署步骤**：
1. 把所有文件上传到服务器
2. 访问线上地址
3. 按测试清单逐项检查

**✅ 验证点 8**：
- 线上地址能正常访问
- 游戏能正常加载和运行
- 无限精力功能在线上也生效
- CDN 加载正常（如果某个 CDN 被墙，会自动切换）

### 25.11 完整错误速查表

| 错误现象 | 可能原因 | 精确修复步骤 |
|---------|---------|-------------|
| 页面空白，什么都没有 | Ruffle 没加载成功 / JS 报错 | 1. F12 看 Console 错误<br>2. 看 Network 面板 ruffle.js 是不是 404<br>3. 检查 CDN 地址是否正确 |
| 提示"所有 CDN 源都无法访问" | 网络问题 / CDN 被墙 | 1. 检查网络连接<br>2. 换个 CDN 源试试<br>3. 考虑自托管 Ruffle |
| 游戏黑屏 / 一直加载中 | SWF 文件找不到 / 加载失败 | 1. 检查 SWF 文件路径对不对<br>2. F12 → Network 看 SWF 请求是不是 404<br>3. 确认文件名大小写正确 |
| 游戏报错"无法加载" | SWF 文件损坏 / 格式不对 | 1. 确认是有效的 SWF 文件<br>2. 用 FFDec 打开试试<br>3. 可能是加壳的 SWF，Ruffle 不支持 |
| 修改后游戏崩了 | 修改引入了 bug | 1. 撤销修改，重新来<br>2. 小步修改，每次改一点就测试<br>3. 用二分法定位哪次修改出的问题 |
| 修改没效果 | 改错了地方 | 1. 确认修改的是正确的函数<br>2. 可能有多个地方减能量，都要改<br>3. 加个 trace/console.log 确认函数有没有被调用 |
| 手机上玩不了 | 移动端兼容性 / 触控问题 | 1. Ruffle 移动端支持有限<br>2. 试试不同的浏览器<br>3. Flash 游戏很多是鼠标操作，手机操控体验差 |
| 声音很小 / 没有声音 | 浏览器自动播放策略 | 1. 需要用户交互后才能播放声音<br>2. 点击一下游戏画面试试<br>3. 检查系统音量 |
| 游戏速度不对 | 帧率问题 / Ruffle 兼容性 | 1. 不同的 Ruffle 版本可能有差异<br>2. 换个版本试试<br>3. 这是模拟器的正常现象 |
| FFDec 保存后 SWF 变大了 | 保存格式变了（CWS → FWS） | 1. 正常现象，FWS 是未压缩格式<br>2. 不影响功能，只是体积变大<br>3. 如果在意体积，可以用 swftools 重新压缩 |

### 25.12 上线前验收清单

- [ ] 桌面端 Chrome 正常运行
- [ ] 桌面端 Firefox 正常运行
- [ ] 桌面端 Edge 正常运行
- [ ] 原版游戏功能正常
- [ ] 修改版功能正常（无限精力生效）
- [ ] 切换按钮工作正常
- [ ] 页面布局美观
- [ ] 控制台没有报错
- [ ] 没有 404 / 500 错误
- [ ] 刷新页面功能正常
- [ ] CDN 切换功能测试过
- [ ] 手机端能加载（Flash 游戏移动端体验有限）

---

**文档版本**：v1.4（完整复刻版）
**最后更新**：2026-10-05
**更新内容**：新增完整复刻部署指南（12 个步骤+验证点）、完整错误速查表（10 种常见错误）、上线前验收清单
