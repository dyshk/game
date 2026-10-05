# Papery Planes 部署说明（InfinityFree + GitHub Pages 两类方案）

## 概述

本游戏同时支持两种部署方式，对应两套不同的文件结构。

| 平台 | 方案 | 核心机制 | 入口文件 |
|------|------|---------|---------|
| **InfinityFree** | 分块文件 + PHP 合并 | 服务器端 PHP 动态合成分块 | `index-infinityfree.html` |
| **GitHub Pages** | 完整文件 + 静态托管 | 直接加载完整文件，无需合并 | `index.html` |

---

## 一、InfinityFree 方案（PHP 分块合并）

### 适用场景
- InfinityFree 等支持 PHP + .htaccess 的虚拟主机
- 游戏文件较大（超过 100MB），需要分块上传

### 原理
游戏资源文件被分成多个 `.part` 小块。玩家访问时，`unityweb.php` 读取所有分块并在服务器端合并，然后输出给 Unity 加载。

### 专属文件（InfinityFree 用）

| 文件/目录 | 说明 |
|-----------|------|
| `index-infinityfree.html` | InfinityFree 版入口页面（原版，未修改） |
| `Build/` | 资源目录（分块文件 + PHP 合并脚本） |
| `Build/ppw.data.unityweb.part0` | 游戏数据分块 0 |
| `Build/ppw.data.unityweb.part1` | 游戏数据分块 1 |
| `Build/ppw.wasm.code.unityweb.part0` | WASM 代码分块 0 |
| `Build/ppw.wasm.code.unityweb.part1` | WASM 代码分块 1 |
| `Build/ppw.wasm.code.unityweb.part2` | WASM 代码分块 2 |
| `Build/ppw.wasm.framework.unityweb` | Framework JS（不分块） |
| `Build/ppw.json` | Unity 配置文件（路径指向分块） |
| `Build/unityweb.php` | PHP 分组合并脚本 |
| `Build/.htaccess` | URL 重写规则（把 .unityweb 请求转发到 PHP） |

### 部署到 InfinityFree

1. 把 `index-infinityfree.html` 重命名为 `index.html`（覆盖当前 GitHub 版的 index.html）
2. 上传整个 `Build/` 目录
3. 上传 `UnityLoader.2019.1.js`
4. 确保主机支持 PHP 和 .htaccess

---

## 二、GitHub Pages 方案（完整文件 + 静态托管）

### 适用场景
- GitHub Pages 等纯静态托管平台（不支持 PHP）
- 游戏总大小在 100MB 以内（GitHub Pages 单文件限制）

### 原理
游戏资源文件是合并后的完整文件，Unity 直接通过 HTTP 加载，不需要服务器端处理。

> **为什么不用分块？**
> GitHub Pages 单文件限制 100MB，本游戏最大文件仅 ~20MB，完全不需要分块。
> 完整文件方案更简单、加载更快、兼容性更好。

### 专属文件（GitHub Pages 用）

| 文件/目录 | 说明 |
|-----------|------|
| `index.html` | GitHub Pages 版入口页面（已修改） |
| `ppw-github.json` | GitHub 版 Unity 配置（路径指向 Build-github） |
| `Build-github/` | 资源目录（合并后的完整文件） |
| `Build-github/ppw.data.unityweb` | 游戏数据（完整，不分块，~12MB） |
| `Build-github/ppw.wasm.code.unityweb` | WASM 代码（完整，不分块，~20MB） |
| `Build-github/ppw.wasm.framework.unityweb` | Framework JS（完整，不分块，~0.5MB） |

### index.html 修改说明

GitHub 版的 `index.html` 相比原版做了两处修改：

#### 修改 1：新增 fetch 路径拦截器

Unity WebGL 的 framework JS 加载 wasm code 时，会硬编码加上 `Build/` 前缀，导致路径变成 `Build/Build-github/ppw.wasm.code.unityweb`（404 错误）。

用一个轻量的 fetch 拦截器修正路径：

```js
(function() {
    var _origFetch = window.fetch;
    window.fetch = function(input, init) {
        var url = typeof input === 'string' ? input : (input && input.url);
        if (url && url.indexOf('Build/Build-github/') !== -1) {
            var fixedUrl = url.replace('Build/Build-github/', 'Build-github/');
            return _origFetch(fixedUrl, init);
        }
        return _origFetch.apply(this, arguments);
    };
})();
```

#### 修改 2：Unity 启动配置改为 ppw-github.json

```js
// 原（InfinityFree 版）：从 Build/ 加载 JSON
UnityLoader.instantiate("gameContainer", "Build/ppw.json", params);

// 新（GitHub 版）：加载 GitHub 版配置
UnityLoader.instantiate("gameContainer", "ppw-github.json", params);
```

### 部署到 GitHub Pages

1. 上传 `index.html`（GitHub Pages 版本）
2. 上传 `ppw-github.json`
3. 上传整个 `Build-github/` 目录（3 个完整文件）
4. 上传 `UnityLoader.2019.1.js`
5. 上传 `explanation-github.md`（可选，本说明文件）
6. 上传 `index-infinityfree.html`（可选，备份）
7. 上传 `Build/` 目录（可选，InfinityFree 用；GitHub Pages 上是普通静态文件，不影响）
8. 等 GitHub Pages 部署完成后访问

---

## 三、共用文件

以下文件两个平台通用，不需要修改：

| 文件 | 说明 |
|------|------|
| `UnityLoader.2019.1.js` | Unity WebGL 加载器 |
| `technical-documentation.md` | 技术文档 |
| `explanation-github.md` | 本说明文件 |

---

## 四、两类方案对比

| 特性 | InfinityFree (PHP) | GitHub Pages (完整文件) |
|------|-------------------|----------------------|
| 托管类型 | PHP 虚拟主机 | 纯静态托管 |
| 文件形式 | 分块（.part0/.part1/...） | 完整文件 |
| 合并位置 | 服务器端（PHP） | 无需合并 |
| 依赖 | PHP + .htaccess | 纯静态，无依赖 |
| 加载速度 | 快（流式输出） | 快（直接加载） |
| 兼容性 | 所有浏览器 | 所有浏览器 |
| 适用文件大小 | 无限制（分块可任意大） | 单文件 ≤ 100MB |
| 入口文件 | `index-infinityfree.html` | `index.html` |
| 资源目录 | `Build/` | `Build-github/` |

---

## 五、文件总清单

```
papery-planes/
├── index.html                      ← GitHub Pages 入口（当前使用）
├── index-infinityfree.html         ← InfinityFree 入口（备份）
├── ppw-github.json                 ← GitHub 版 Unity 配置
├── UnityLoader.2019.1.js           ← 共用：Unity 加载器
├── explanation-github.md           ← 共用：本说明文件
├── technical-documentation.md      ← 共用：技术文档
├── Build/                          ← InfinityFree 资源（分块 + PHP）
│   ├── .htaccess
│   ├── unityweb.php
│   ├── ppw.json
│   ├── ppw.data.unityweb.part0
│   ├── ppw.data.unityweb.part1
│   ├── ppw.wasm.code.unityweb.part0
│   ├── ppw.wasm.code.unityweb.part1
│   ├── ppw.wasm.code.unityweb.part2
│   └── ppw.wasm.framework.unityweb
└── Build-github/                   ← GitHub Pages 资源（完整文件）
    ├── ppw.data.unityweb
    ├── ppw.wasm.code.unityweb
    └── ppw.wasm.framework.unityweb
```

### 按平台分类

| 平台 | 必须文件 | 可选文件 |
|------|---------|---------|
| **InfinityFree** | `index-infinityfree.html`（需改名 index.html）、`Build/`、`UnityLoader.2019.1.js` | 其他所有文件 |
| **GitHub Pages** | `index.html`、`ppw-github.json`、`Build-github/`、`UnityLoader.2019.1.js` | 其他所有文件 |

---

## 六、常见问题

### Q: 怎么切换到 InfinityFree 版本？

把 `index-infinityfree.html` 重命名为 `index.html` 即可（覆盖当前 GitHub 版）。InfinityFree 版本使用 `Build/` 目录下的分块文件和 `unityweb.php`。

### Q: 怎么切换到 GitHub Pages 版本？

当前 `index.html` 就是 GitHub Pages 版本，不需要额外操作。确保 `ppw-github.json` 和 `Build-github/` 目录存在即可。

### Q: 两个平台的文件可以放一起吗？

可以。`Build/` 和 `Build-github/` 是两个独立目录，互不影响。GitHub Pages 上 `unityweb.php` 和 `.htaccess` 只是普通静态文件，不会被执行，也不会影响功能。

### Q: 为什么 Build-github 不直接叫 Build？

因为 `Build/` 是 InfinityFree 版本在用的（分块文件 + PHP 脚本），需要保留。GitHub Pages 版本用独立目录，避免互相干扰。

### Q: 为什么 ppw-github.json 放在根目录，不放在 Build-github 里？

UnityLoader 加载 JSON 时，dataUrl 和 frameworkUrl 相对于 JSON 所在目录解析；但 wasm code URL 会被 framework JS 额外加上 `Build/` 前缀。

如果 JSON 在 Build-github 里，dataUrl 用相对路径能正确解析，但 wasmCodeUrl 会变成 `Build/ppw.wasm.code.unityweb`（错误路径）。

把 JSON 放根目录，路径统一用 `Build-github/` 前缀，再用 fetch 拦截器修正 wasm code 的 `Build/` 前缀问题，是目前最可靠的方案。

### Q: fetch 拦截器会影响其他请求吗？

不会。拦截器只处理 URL 中包含 `Build/Build-github/` 的请求，其他请求直接放行。

### Q: 游戏文件多大？GitHub Pages 100MB 限制够吗？

- ppw.data.unityweb: ~12MB
- ppw.wasm.code.unityweb: ~20MB
- ppw.wasm.framework.unityweb: ~0.5MB
- **总计: ~32.5MB**

远低于 GitHub Pages 100MB 的单文件限制，完全没问题。
