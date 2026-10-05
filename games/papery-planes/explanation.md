# Papery Planes - GitHub Pages 部署说明

## 问题现象

Unity WebGL 游戏在 GitHub Pages 上无法打开（`https://dyshk.github.io/game/games/papery-planes/index.html`），但在本地和 InfinityFree 上都能正常运行。

## 根本原因

Unity WebGL 构建的大文件被拆分成 `.partN` 分块，用于绕过 InfinityFree 的 10MB 单文件上传限制。原本通过 PHP 脚本（`unityweb.php`）配合 `.htaccess` 的 mod_rewrite 规则，在浏览器请求 `.unityweb` 文件时动态合并分块。

**GitHub Pages 是纯静态托管，不支持 PHP 和 `.htaccess`**，导致浏览器请求 `ppw.data.unityweb` 时返回 404：
1. 完整的 `.unityweb` 文件不存在（只有 `.part0`、`.part1` 等分块）
2. `unityweb.php` 无法执行，不能合并分块
3. `.htaccess` 重写规则被忽略

## 进行的改动

### 1. 新建 `Build-github/` 目录

合并分块后的完整 `.unityweb` 文件，GitHub Pages 可直接静态服务：

| 文件 | 大小 | 来源 |
|------|------|------|
| `ppw.data.unityweb` | 12 MB | `part0` + `part1` 合并 |
| `ppw.wasm.code.unityweb` | 20 MB | `part0` + `part1` + `part2` 合并 |
| `ppw.wasm.framework.unityweb` | 520 KB | 直接复制（原本即完整） |
| `ppw.json` | 527 B | 直接复制 |

### 2. 修改 `index.html`

将 Unity 加载器路径从 `Build/` 改为 `Build-github/`：

```diff
- "Build/ppw.json?v=24012020"
+ "Build-github/ppw.json?v=24012020"
```

### 3. 备份 `index-bak-infinityfree.html`

保留原始 `index.html`（指向 `Build/`，配合 PHP 合并方案），用于 InfinityFree 部署。

## 目录结构

### 顶层目录

```
papery-planes/
├── Build/                      # InfinityFree 部署（未改动）
├── Build-github/               # GitHub Pages 部署（新建）
├── UnityLoader.2019.1.js       # Unity 加载器（未改动）
├── index.html                  # 已修改 → 指向 Build-github/
├── index-bak-infinityfree.html # 备份（原始 index.html）
├── technical-documentation.md  # 原始技术文档
└── explanation.md              # 本说明文件
```

### `Build/`（InfinityFree 专用，未改动）

```
Build/
├── .htaccess                        # Apache mod_rewrite 重写规则
├── unityweb.php                     # PHP 分块合并脚本
├── ppw.json                         # 配置文件
├── ppw.data.unityweb.part0          # 9 MB 分块
├── ppw.data.unityweb.part1          # 3 MB 分块
├── ppw.wasm.code.unityweb.part0     # 9 MB 分块
├── ppw.wasm.code.unityweb.part1     # 9 MB 分块
├── ppw.wasm.code.unityweb.part2     # 2 MB 分块
└── ppw.wasm.framework.unityweb      # 520 KB（完整，无需分块）
```

### `Build-github/`（GitHub Pages 专用，新建）

```
Build-github/
├── ppw.data.unityweb                # 12 MB（合并后的完整文件）
├── ppw.wasm.code.unityweb           # 20 MB（合并后的完整文件）
├── ppw.wasm.framework.unityweb      # 520 KB（直接复制）
└── ppw.json                         # 527 B（直接复制）
```

## 部署指南

### GitHub Pages

1. 推送整个 `papery-planes/` 目录到 GitHub
2. `index.html` 已指向 `Build-github/`，无需额外修改
3. 访问：`https://dyshk.github.io/game/games/papery-planes/index.html`

### InfinityFree

1. 将 `index-bak-infinityfree.html` 重命名为 `index.html`
2. 上传 `Build/` 目录（含 `.htaccess`、`unityweb.php` 和 `.partN` 分块）
3. PHP 脚本会在请求时动态合并分块

## 注意事项

- `Build/` 目录**未改动**，仍适用于 InfinityFree
- 主页 `C:\Users\Admin\Desktop\game\index.html` **不需要修改** —— 它只链接到 `games/papery-planes/index.html`，未直接引用 `Build/` 路径
- GitHub Pages 单文件限制 100 MB，远大于最大文件（20 MB）
- 所有文件名均为小写，无大小写敏感问题
