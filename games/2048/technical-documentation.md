# 2048 游戏技术文档

**游戏名称**：2048
**类型**：HTML5 数字益智游戏（纯前端）
**技术方案**：原生 JavaScript + CSS3 + DOM 渲染
**部署方式**：纯静态文件，可部署到任何静态托管（GitHub Pages / InfinityFree / Vercel 等）

---

## 目录

1. [项目概述](#1-项目概述)
2. [文件结构](#2-文件结构)
3. [架构设计：MV* 模式](#3-架构设计mv-模式)
4. [核心模块详解](#4-核心模块详解)
5. [游戏核心算法](#5-游戏核心算法)
6. [动画实现原理](#6-动画实现原理)
7. [数据持久化](#7-数据持久化)
8. [移动端适配](#8-移动端适配)
9. [部署指南](#9-部署指南)
10. [故障排查](#10-故障排查)

---

## 1. 项目概述

### 1.1 项目背景

2048 是由 Gabriele Cirulli 在 2014 年创建的一款经典数字益智游戏，玩家通过上下左右滑动，将相同数字的方块合并，目标是合成 2048。

本项目基于原版 2048（MIT 协议），做了以下调整：
- 添加返回主页按钮（游戏合集导航）
- 移除 IE6-9 兼容 polyfill（精简体积）
- 移除废弃字体格式（只保留 woff）

### 1.2 技术选型

| 组件 | 选择 | 原因 |
|------|------|------|
| 渲染方式 | DOM + CSS3 | 4x4 网格规模小，DOM 动画流畅且实现简单 |
| 架构模式 | MV*（类 Backbone） | 逻辑与视图分离，易维护 |
| 数据存储 | localStorage | 纯前端持久化，无需后端 |
| 构建工具 | 无（原生 JS） | 代码量小，不需要打包 |
| 字体 | ClearSans (woff) | 现代浏览器都支持 woff，体积比 ttf 小 |

### 1.3 为什么不用 Canvas？

2048 只有 16 个格子 + 若干方块，DOM 完全够用：
- CSS3 transition 动画比手写 Canvas 动画省代码
- 可以直接用 CSS 类控制样式状态
- 无障碍性更好（可读、可选中）
- 开发效率高

Canvas 的优势（大量元素、粒子效果等）在这个游戏里体现不出来。

---

## 2. 文件结构

```
2048/
├── index.html                    # 主页面，页面结构 + 返回按钮
├── favicon.ico                   # 网站图标
├── meta/
│   └── apple-touch-icon.png      # iOS 添加到主屏图标
├── js/
│   ├── application.js            # 入口，初始化游戏
│   ├── game_manager.js           # 游戏核心逻辑（Model + Controller）
│   ├── grid.js                   # 网格数据结构
│   ├── tile.js                   # 方块数据结构
│   ├── html_actuator.js          # 视图渲染（View）
│   ├── keyboard_input_manager.js # 输入管理（键盘+触摸）
│   └── local_storage_manager.js  # 本地存储
└── style/
    ├── main.css                  # 游戏全部样式
    └── fonts/
        ├── clear-sans.css        # 字体声明
        ├── ClearSans-Regular-webfont.woff
        ├── ClearSans-Bold-webfont.woff
        └── ClearSans-Light-webfont.woff
```

### 关键文件大小

| 文件 | 大小 | 说明 |
|------|------|------|
| index.html | ~3 KB | 结构简单，只有 7 个 JS 引入 |
| game_manager.js | ~8 KB | 核心逻辑，最大的 JS 文件 |
| main.css | ~15 KB | 包含所有动画和响应式样式 |
| 全部 JS（合计） | ~25 KB | 未压缩，gzip 后约 8 KB |

---

## 3. 架构设计：MV* 模式

### 3.1 整体架构

2048 采用了类似 Backbone 的 **MV\* 架构**，但没有使用任何框架，纯手写实现：

```
┌─────────────────────────────────────────────┐
│  application.js（入口）                       │
│    实例化 GameManager                        │
└──────────────────┬──────────────────────────┘
                   │
         ┌─────────┴─────────┐
         ▼                   ▼
┌─────────────────┐  ┌─────────────────┐
│  InputManager   │  │  StorageManager │
│  (键盘+触摸)    │  │  (localStorage) │
└────────┬────────┘  └────────┬────────┘
         │                    │
         └─────────┬──────────┘
                   ▼
         ┌─────────────────┐
         │  GameManager    │  ← Controller + Model
         │  (游戏状态/逻辑) │
         └────────┬────────┘
                  │
                  ▼
         ┌─────────────────┐
         │  HTMLActuator   │  ← View
         │  (DOM 渲染)     │
         └─────────────────┘
```

### 3.2 各模块职责

| 模块 | 角色 | 职责 |
|------|------|------|
| **GameManager** | Model + Controller | 管理游戏状态（分数、网格、胜负），处理移动逻辑 |
| **Grid** | Model | 4x4 网格数据结构，管理方块位置 |
| **Tile** | Model | 单个方块的数据（位置、数值、上一帧位置） |
| **HTMLActuator** | View | 根据游戏状态更新 DOM，渲染动画 |
| **InputManager** | Controller 辅助 | 监听键盘和触摸事件，转换为游戏指令 |
| **StorageManager** | 数据层 | 读写 localStorage，保存/恢复游戏进度 |
| **application.js** | 入口 | 组装各个模块，启动游戏 |

### 3.3 事件机制

InputManager 和 GameManager 之间用**自定义事件**解耦：

```js
// InputManager 触发事件
this.emit("move", direction);
this.emit("restart");
this.emit("keepPlaying");

// GameManager 监听事件
this.inputManager.on("move", this.move.bind(this));
this.inputManager.on("restart", this.restart.bind(this));
this.inputManager.on("keepPlaying", this.keepPlaying.bind(this));
```

这是经典的发布-订阅模式，好处是输入层和游戏逻辑完全解耦——想加手柄支持、语音控制，只需要扩展 InputManager 就行，不用改 GameManager。

---

## 4. 核心模块详解

### 4.1 Grid（网格）

4x4 的二维数据结构，用一维数组模拟二维：

```js
function Grid(size, previousState) {
    this.size = size;
    this.cells = previousState ? this.fromState(previousState) : this.empty();
}

// cells[y][x] = tile 或 null
// 注意：先 y 后 x，行优先
```

**关键方法：**

| 方法 | 作用 | 时间复杂度 |
|------|------|-----------|
| `cellsAvailable()` | 是否还有空格 | O(n²) |
| `randomAvailableCell()` | 随机返回一个空格位置 | O(n²) |
| `cellContent(cell)` | 获取指定位置的方块 | O(1) |
| `eachCell(callback)` | 遍历所有格子 | O(n²) |
| `withinBounds(position)` | 判断位置是否在网格内 | O(1) |

**为什么用二维数组而不是一维？**
- 代码可读性更好（`cells[y][x]` 比 `cells[y*size+x]` 直观）
- 4x4 只有 16 个元素，性能差异可以忽略
- 序列化/反序列化直接 JSON 就能处理

### 4.2 Tile（方块）

每个方块有三个核心属性：

```js
function Tile(position, value) {
    this.x = position.x;
    this.y = position.y;
    this.value = value || 2;     // 数值：2, 4, 8, 16...
    this.previousPosition = null; // 上一帧位置（动画用）
    this.mergedFrom = null;       // 由哪两个方块合并而来（动画用）
}
```

**`previousPosition` 的作用：**
- 每次移动前，调用 `tile.savePosition()` 保存当前位置
- 移动后，新位置写入 `x` 和 `y`
- HTMLActuator 根据"旧位置 → 新位置"做 CSS 过渡动画

**`mergedFrom` 的作用：**
- 记录合并来源（两个旧方块）
- 渲染时：两个旧方块移动到目标位置，然后消失，新方块从那里"弹出"
- 这样就实现了合并的视觉效果

### 4.3 InputManager（输入管理）

同时支持键盘和触摸，统一转换成 `move` 事件：

**键盘：**
- 方向键 / WASD → 四个方向移动
- 事件：`keydown`

**触摸：**
- 监听 `touchstart` 和 `touchend`
- 计算滑动向量（dx, dy）
- 取绝对值更大的那个方向
- 最小滑动距离阈值（防止误触）

```js
// 触摸滑动判断原理
var dx = touchEnd.x - touchStart.x;
var dy = touchEnd.y - touchStart.y;

if (Math.abs(dx) > Math.abs(dy)) {
    // 水平滑动
    direction = dx > 0 ? 1 : 3; // 右 : 左
} else {
    // 垂直滑动
    direction = dy > 0 ? 2 : 0; // 下 : 上
}
```

### 4.4 HTMLActuator（视图渲染）

**核心方法：`actuate(grid, metadata)`**

每次游戏状态变化时调用，做两件事：
1. **更新分数**（普通分数 + 最高分）
2. **更新方块**（移除旧的、添加新的、移动现有方块）

**渲染策略：**
- 不是每次都重建全部 DOM
- 给每个 tile 一个唯一 `id`（根据位置和数值生成）
- 通过 CSS class 控制位置（`tile-position-x-y`）
- CSS transition 自动处理动画

**为什么不用 React/Vue？**
- 游戏只有十几个动态元素，手动 DOM 操作比框架更轻量
- 2014 年写的，那时候 React 刚出来
- 代码量小，维护成本低

---

## 5. 游戏核心算法

### 5.1 移动算法

移动是整个游戏最核心的逻辑，在 `GameManager.move(direction)` 中实现。

#### 算法步骤

```
以"向左移动"为例：

1. 准备阶段：
   - 保存所有方块的当前位置（用于动画）
   - 清除 mergedFrom 标记

2. 遍历顺序：
   - 从左到右，从上到下
   - （方向不同，遍历顺序也不同）

3. 对每个方块：
   a. 找到最远能滑到的位置（一路没障碍物）
   b. 检查下一个位置的方块：
      - 如果数值相同且没被合并过 → 合并
      - 否则 → 滑到最远位置

4. 如果有任何方块移动了：
   a. 添加一个新方块（2 或 4）
   b. 检查是否还有可移动的（没有就 Game Over）
   c. 更新视图
```

#### 遍历顺序的重要性

移动方向决定了遍历顺序，**必须从"目标方向那一侧"开始遍历**：

| 方向 | x 遍历顺序 | y 遍历顺序 | 原因 |
|------|-----------|-----------|------|
| 上 | 正序 | 正序 | 先处理上面的方块，下面的往上滑不会撞 |
| 下 | 正序 | 逆序 | 先处理下面的方块 |
| 左 | 正序 | 正序 | 先处理左边的方块 |
| 右 | 逆序 | 正序 | 先处理右边的方块 |

如果顺序搞反了，一个方块可能在同一帧内被多次合并（比如 2 + 2 + 2 + 2 变成 8 而不是 4 + 4），这是经典 bug。

#### 合并规则

- 每次移动中，每个方块最多参与一次合并
- 用 `mergedFrom` 标记已经合并过的方块
- 两个方块合并后，新方块的位置是"被撞的那个"的位置

### 5.2 胜负判断

**胜利条件：** 出现数值为 2048 的方块
- 合并时检查 `merged.value === 2048`
- 设置 `this.won = true`
- 玩家可以选择"Keep going"继续玩更大的数

**失败条件：** 没有空格子 **且** 没有相邻相同数值的方块
- 先检查 `cellsAvailable()`（有没有空格）
- 没有空格时，检查 `tileMatchesAvailable()`（每个方块四个方向有没有相同的）
- 两者都不满足 → Game Over

```js
// 性能优化：先检查空格（O(n²) 但通常很快返回）
// 只有没空格时才检查相邻匹配（O(n²) 且要检查4个方向）
GameManager.prototype.movesAvailable = function () {
    return this.grid.cellsAvailable() || this.tileMatchesAvailable();
};
```

### 5.3 新方块生成

- 90% 概率生成 2，10% 概率生成 4
- 在所有空格中随机选一个
- 用 `Math.random()` 生成随机数

```js
var value = Math.random() < 0.9 ? 2 : 4;
```

这个 90/10 的比例是原版游戏的设计，保证游戏节奏合适——4 太多会让游戏太容易，2 太多会太慢。

---

## 6. 动画实现原理

### 6.1 移动动画

**实现方式：CSS transition + class 切换**

```css
.tile {
    transition: 100ms ease-in-out;
    transition-property: transform;
}
```

每个方块用 `transform: translate(x, y)` 定位，位置变化时自动过渡。

**步骤：**
1. 移动前：`savePosition()` 保存旧位置
2. 移动后：更新 `x`、`y` 属性
3. 渲染时：移除旧位置 class，添加新位置 class
4. CSS transition 自动处理 100ms 的滑动动画

### 6.2 合并动画

合并动画分两个阶段：

**阶段 1：两个旧方块滑向合并点**
- 和普通移动动画一样，100ms

**阶段 2：新方块"弹出"效果**
- 新方块添加 `tile-merged` class
- CSS 动画：scale 从 1.2 → 1，持续 200ms
- 配合出现时机，给玩家"合成了"的反馈

```css
.tile-merged {
    z-index: 20;
    animation: pop 200ms ease 100ms;
    animation-fill-mode: backwards;
}

@keyframes pop {
    0% { transform: scale(0); }
    50% { transform: scale(1.2); }
    100% { transform: scale(1); }
}
```

### 6.3 新方块出现动画

新生成的方块有一个"冒出来"的效果：
- 添加 `tile-new` class
- scale 从 0 → 1，150ms
- 延迟 50ms 开始（等其他方块先移动到位）

### 6.4 分数增加动画

分数变化时，新增一个 `.score-addition` 元素，从原位置向上飘动并淡出：

```css
.score-addition {
    position: absolute;
    right: 30px;
    color: #776e65;
    animation: move-up 600ms ease-in;
    animation-fill-mode: both;
}

@keyframes move-up {
    0% { top: 25px; opacity: 1; }
    100% { top: -30px; opacity: 0; }
}
```

每次加分都创建一个新元素，动画结束后移除。玩家能直观看到"加了多少分"。

---

## 7. 数据持久化

### 7.1 存储内容

用 `localStorage` 保存两类数据：

| Key | 内容 | 什么时候保存 |
|-----|------|-------------|
| `bestScore` | 历史最高分 | 每次分数变化时更新 |
| `gameState` | 当前游戏状态（网格+分数+胜负） | 每次有效移动后保存 |

### 7.2 序列化格式

```js
// gameState 的结构
{
    grid: {
        size: 4,
        cells: [
            // 二维数组，每个元素是 tile 或 null
            // tile: { x, y, value }
        ]
    },
    score: 2048,
    over: false,
    won: true,
    keepPlaying: true
}
```

直接用 `JSON.stringify` / `JSON.parse` 序列化。

### 7.3 恢复策略

```js
// GameManager.setup()
var previousState = this.storageManager.getGameState();

if (previousState) {
    // 有存档 → 恢复
    this.grid = new Grid(previousState.grid.size, previousState.grid.cells);
    this.score = previousState.score;
    // ...
} else {
    // 没存档 → 新游戏
    this.grid = new Grid(this.size);
    this.score = 0;
    this.addStartTiles();
}
```

### 7.4 Game Over 时清档

游戏结束（`over === true`）时，清除 gameState：
```js
if (this.over) {
    this.storageManager.clearGameState();
}
```

**原因：** Game Over 后不应该让玩家"继续上次的游戏"，因为上次的游戏已经输了。下次打开应该是全新的游戏。

但胜利（`won === true`）不清档，因为玩家可以选择"Keep going"继续玩。

---

## 8. 移动端适配

### 8.1 Viewport 设置

```html
<meta name="viewport" content="width=device-width, initial-scale=1.0,
    maximum-scale=1, user-scalable=no, minimal-ui">
```

- `maximum-scale=1` + `user-scalable=no`：禁止双指缩放（游戏需要滑动操作）
- `minimal-ui`：iOS Safari 隐藏地址栏（全屏体验）

### 8.2 触摸操作

InputManager 同时监听键盘和触摸：

- `touchstart`：记录起始位置
- `touchend`：计算结束位置，判断滑动方向
- 最小滑动距离：20px（防止误触）

### 8.3 响应式布局

游戏容器用百分比 + max-width 适配：

```css
.container {
    width: 500px;
    margin: 0 auto;
}

@media screen and (max-width: 520px) {
    .container { width: auto; margin: 0 10px; }
    /* 缩小字体、间距等 */
}
```

小屏幕上：
- 游戏区域自适应屏幕宽度
- 字体缩小
- 按钮和间距缩小
- 保持正方形比例

---

## 9. 部署指南

### 9.1 部署要求

- 任何静态文件托管服务
- 不需要后端、不需要数据库
- 不需要构建工具

### 9.2 部署步骤

1. 把 `2048/` 整个目录上传到服务器
2. 访问对应 URL 即可

### 9.3 缓存建议

```apache
# .htaccess 示例
ExpiresByType text/html "access plus 0 seconds"   # HTML 不缓存
ExpiresByType text/css "access plus 7 days"        # CSS 缓存7天
ExpiresByType application/javascript "access plus 7 days"  # JS 缓存7天
ExpiresByType image/png "access plus 30 days"      # 图片缓存30天
ExpiresByType font/woff "access plus 30 days"      # 字体缓存30天
```

HTML 不缓存是为了更新内容后用户能立即看到。

### 9.4 压缩

文本类资源（HTML/CSS/JS）一定要开 gzip/brotli：
- 纯文本压缩比通常 70%+
- 游戏 JS 从 25KB 压到 8KB
- 服务器配置（Apache 的 mod_deflate 或 Nginx 的 gzip）

**二进制文件不压缩：**
- woff 字体本身就是压缩格式
- ico/png 也是压缩格式
- 再压缩反而浪费 CPU

---

## 10. 故障排查

### 10.1 游戏加载不出来

**可能原因：**
1. JS 文件路径不对（404）
2. localStorage 被禁用
3. 浏览器太老（不支持 ES5）

**排查方法：**
- F12 看 Console 错误
- Network 看 JS 文件是否 404
- 检查浏览器版本

### 10.2 触摸没反应

**可能原因：**
1. 被其他元素挡住了（z-index 问题）
2. `touch-action` 被 CSS 禁了
3. 滑动距离太小（没达到阈值）

**排查方法：**
- 大幅度滑动试试
- 检查有没有遮罩层

### 10.3 进度丢失

**可能原因：**
1. 清了浏览器缓存
2. 用了无痕模式
3. localStorage 满了

**说明：** localStorage 是浏览器本地存储，换浏览器、清缓存、换设备都会丢失。这是纯前端游戏的正常现象。

### 10.4 动画卡顿

**可能原因：**
1. 方块太多（到 2048 之后继续玩，方块数量增加）
2. 浏览器 tab 在后台（requestAnimationFrame 被暂停）
3. 低端手机性能不够

**优化方向（如果需要）：**
- 减少动画时间
- 用 `will-change: transform` 提示浏览器优化
- 降低阴影、渐变等耗性能的 CSS

---

## 11. 算法深入分析

### 11.1 移动算法的正确性证明

移动算法是 2048 的核心，它必须满足以下性质：

**性质 1：每个方块在一次移动中最多移动一次位置。**
- 从目标方向那一侧开始遍历，先处理前面的方块
- 后面的方块移动时，前面的已经到位了
- 所以每个方块只会被处理一次

**性质 2：每个方块在一次移动中最多参与一次合并。**
- 合并后的新方块设置了 `mergedFrom` 标记
- 检查合并时，如果 `next.mergedFrom` 不为空，就不合并
- 保证了 2+2+2+2 不会变成 8，而是变成 4+4

**性质 3：移动后所有方块都紧贴目标方向的一侧。**
- 每个方块都滑到最远的可用位置
- 没有空隙（除非被其他方块挡住）

### 11.2 移动算法的时间复杂度

| 操作 | 时间复杂度 | 说明 |
|------|-----------|------|
| 单次移动 | O(n²) | n = 网格边长，4×4 = 16，常数级 |
| 检查胜负 | O(n²) | 检查每个格子 + 相邻匹配 |
| 添加新方块 | O(n²) | 找空格是 O(n²)，但通常很快 |

对于 4×4 的网格，所有操作都是 O(1)（因为 n=4 是常数），性能完全不是问题。

但如果是更大的网格（比如 8×8、16×16），就需要考虑优化了。

### 11.3 最优策略（玩家视角）

虽然这不是代码问题，但了解最优策略有助于理解游戏设计：

**策略 1：角策略（最常用）**
- 把最大的方块固定在一个角落（通常是左下角）
- 保持最大的一排/列单调递增
- 尽量不移动"最大方块的反方向"
- 例如：最大在左下角，就尽量不按"上"和"右"

**策略 2：蛇形策略**
- 方块按蛇形排列：256 → 128 → 64 → 32
- ↑
- 2 → 4 → 8 → 16
- 优点：合并时不用大移动
- 缺点：维持蛇形比较难

**策略 3：中心策略**
- 大方块放中间
- 小方块围绕四周
- 不推荐：大方块在中间容易被堵住

**为什么角策略最优？**
- 最大的方块需要最少的移动（最好不动）
- 角落只有两个方向能移动，最稳定
- 边上的方块能保持单调性

### 11.4 游戏的数学上界

**最大可能的方块是多少？**

4×4 = 16 个格子，理论上最大是 2^16 = 65536？不对。

实际上：
- 每次移动后会新生成一个 2 或 4
- 所有方块数值之和 = 初始值 + 新生成的数值
- 要合成 2^n，需要 2^(n-1) 个 2

**正确的上界：**
- 16 个格子全部填满
- 最大的情况：2, 4, 8, 16, 32, 64, 128, 256, 512, 1024, 2048, 4096, 8192, 16384, 32768, 65536
- 但这样的排列无法通过合法移动得到
- **实际上 2048 的 4×4 模式最大能到 131072**（理论极限，几乎不可能达到）

### 11.5 2048 的 NP 完全性

2048 这类游戏的"最优解问题"已被证明是 NP-hard 的。

**含义：**
- 不存在"快速算法"能算出最优的下一步
- 只能用搜索（类似 AI 下棋）
- 搜索深度越深越准，但越慢

这也是为什么 2048 玩起来有意思——没有简单的公式能赢，需要策略和直觉。

---

## 12. 代码架构深入解读

### 12.1 为什么用原型继承而不是 ES6 class

2048 是 2014 年写的，那时候 ES6 还没普及（Chrome 49 才支持 class 语法）。

**原型继承的写法：**
```js
function GameManager(size) {
    this.size = size;
    // ...
}

GameManager.prototype.move = function(direction) {
    // ...
};
```

**等价的 ES6 class 写法：**
```js
class GameManager {
    constructor(size) {
        this.size = size;
        // ...
    }
    
    move(direction) {
        // ...
    }
}
```

两种方式功能一样，只是语法不同。底层都是原型链。

### 12.2 为什么用构造函数 + new 而不是工厂函数

```js
// 构造函数方式（当前代码）
var gm = new GameManager(4, ...);

// 工厂函数方式
var gm = createGameManager(4, ...);
```

**构造函数的优点：**
- 可以用 `instanceof` 检查类型
- 原型链共享方法，省内存
- 当时的主流写法

**工厂函数的优点：**
- 不需要 new，不容易忘
- 可以用闭包实现私有变量
- 更函数式

对于 2048 这种小项目，区别不大，都是个人风格偏好。

### 12.3 事件系统的实现

InputManager 的事件系统是手写的发布-订阅模式：

```js
function InputManager() {
    this.events = {};  // 事件名 → 回调函数列表
}

InputManager.prototype.on = function(event, callback) {
    if (!this.events[event]) {
        this.events[event] = [];
    }
    this.events[event].push(callback);
};

InputManager.prototype.emit = function(event, data) {
    var callbacks = this.events[event];
    if (callbacks) {
        callbacks.forEach(function(cb) { cb(data); });
    }
};
```

**为什么不直接调用？**
- 直接调用 = InputManager 需要知道 GameManager 的存在
- 事件系统 = InputManager 只管发事件，谁关心谁监听
- 解耦，更容易测试和维护

这是经典的"观察者模式"，也是 Node.js EventEmitter 的简化版。

### 12.4 各模块的依赖关系

```
application.js
    ├── GameManager
    │   ├── Grid
    │   │   └── Tile
    │   ├── HTMLActuator
    │   │   └── DOM 操作
    │   ├── KeyboardInputManager
    │   │   └── 键盘/触摸事件
    │   └── LocalStorageManager
    │       └── localStorage
    └── 启动
```

**依赖方向：** 上层依赖下层，下层不知道上层的存在。
- GameManager 不知道 HTML 长什么样（只管发状态，Actuator 负责渲染）
- GameManager 不知道输入来自键盘还是触摸（只管监听事件）
- GameManager 不知道数据存在哪（只管调用 StorageManager）

这就是依赖倒置原则（DIP）的体现。

---

## 13. 动画系统深入

### 13.1 CSS transition vs JS 动画

**CSS transition（当前方案）：**

```css
.tile {
    transition: 100ms ease-in-out;
    transition-property: transform;
}
```

优点：
- 代码简单
- 性能好（浏览器优化，走 GPU）
- 自动处理补间

缺点：
- 控制有限（不能中途改变）
- 复杂动画难做

**JS 动画（requestAnimationFrame）：**

优点：
- 完全控制每一帧
- 可以做复杂的物理效果
- 可以中途改变动画

缺点：
- 代码量大
- 性能需要自己优化
- 容易掉帧

**为什么 2048 用 CSS transition？**
- 移动动画很简单（位置变化）
- CSS transition 完全够用
- 代码量少，维护简单

### 13.2 动画时序详解

一次移动的完整动画流程：

```
T=0ms   玩家按下方向键
        ↓
T=0ms   游戏逻辑计算（移动、合并、新方块）
        ↓
T=0ms   HTMLActuator 更新 DOM
        ├─ 旧方块移动到新位置（transition 100ms）
        ├─ 合并动画（延迟 100ms，动画 200ms）
        └─ 新方块出现（延迟 150ms，动画 200ms）
        ↓
T=100ms 移动动画完成
        ↓
T=200ms 新方块出现动画完成
        ↓
T=300ms 合并动画完成
        ↓
        玩家可以进行下一次操作
```

**为什么合并动画要延迟 100ms？**
- 等两个方块先滑到一起
- 然后再"弹出"合并的效果
- 视觉上更自然

### 13.3 transform 为什么比 left/top 好

**用 left/top 定位：**
```css
.tile {
    position: absolute;
    left: 100px;
    top: 100px;
    transition: left 100ms, top 100ms;
}
```

**用 transform 定位（当前方案）：**
```css
.tile {
    transform: translate(100px, 100px);
    transition: transform 100ms;
}
```

**transform 更好的原因：**

| 特性 | left/top | transform |
|------|----------|-----------|
| 触发回流（reflow） | ✅ 每次都触发 | ❌ 不触发 |
| 触发重绘（repaint） | ✅ 触发 | ⚠️ 可能触发 |
| GPU 加速 | ❌ 不加速 | ✅ 自动加速 |
| 性能 | 差 | 好 |

**为什么 transform 性能好？**
- `transform` 不影响布局，所以不会触发 reflow
- 浏览器可以把元素提升到独立的合成层（compositor layer）
- GPU 直接做变换，CPU 不用参与

### 13.4 will-change 属性

```css
.tile {
    will-change: transform;
}
```

`will-change` 告诉浏览器："这个元素马上要变了，提前准备好。"

**作用：**
- 浏览器提前把元素提升到合成层
- 动画开始时更流畅（没有"卡一下"的感觉）

**副作用：**
- 占用更多显存（每个合成层都需要显存）
- 滥用反而会降低性能

**2048 为什么不用？**
- 方块数量少（最多十几个），性能足够
- `will-change` 是后来才有的属性
- 不用也很流畅

---

## 14. 数据结构与性能分析

### 14.1 网格数据结构

当前实现用二维数组：
```js
this.cells[y][x] = tile 或 null
```

**二维数组的优缺点：**

| 优点 | 缺点 |
|------|------|
| 直观，容易理解 | 多一层索引 |
| 随机访问 O(1) | 序列化稍微麻烦 |
| 遍历方便 | 内存开销略大（多个数组对象） |

**一维数组的等价实现：**
```js
this.cells[y * size + x] = tile 或 null
```

一维数组性能略好（少一次索引），但代码可读性差一点。对于 4×4 的网格，差异可以忽略。

### 14.2 空格子查找效率

`randomAvailableCell()` 需要找一个随机的空格子：

```js
// 当前实现：先收集所有空格子，再随机选一个
Grid.prototype.randomAvailableCell = function() {
    var cells = this.availableCells();  // 收集所有空格
    if (cells.length) {
        return cells[Math.floor(Math.random() * cells.length)];
    }
};
```

**时间复杂度：** O(n²)，需要遍历所有格子。

**优化思路（对于大网格）：**
1. 维护一个空格子列表，增删时更新 → O(1) 随机访问
2. 拒绝采样：随机选一个位置，有东西就重新选 → 空格多时 O(1)，空格少时慢
3. 用稀疏数组 → 实现复杂

对于 4×4 网格，O(n²) 完全没问题，不需要优化。

### 14.3 胜负判断的优化

`movesAvailable()` 的实现：
```js
GameManager.prototype.movesAvailable = function () {
    return this.grid.cellsAvailable() || this.tileMatchesAvailable();
};
```

**短路求值的优化：**
- `cellsAvailable()` 比较快（只要有一个空格就返回 true）
- `tileMatchesAvailable()` 比较慢（要检查每个格子的 4 个方向）
- 先用 `||` 短路，如果有空格就不用检查匹配了

**为什么不反过来？**
- 如果先检查匹配，每次都要做完整的 O(n²) 检查
- 先检查空格，游戏大部分时候都有空格，直接返回 true

这是一个小但实用的性能优化技巧。

### 14.4 序列化与反序列化

存档时把整个游戏状态序列化成 JSON：

```js
GameManager.prototype.serialize = function () {
    return {
        grid: this.grid.serialize(),
        score: this.score,
        over: this.over,
        won: this.won,
        keepPlaying: this.keepPlaying
    };
};
```

**Grid 的序列化：**
```js
Grid.prototype.serialize = function () {
    return {
        size: this.size,
        cells: this.cells  // 二维数组，tile 也是对象
    };
};
```

**Tile 自动序列化：**
- Tile 有 x、y、value 属性
- JSON.stringify 会自动把这些属性转成 JSON
- 反序列化后是普通对象，不是 Tile 实例（需要重新构造）

**为什么不直接存整个 Grid 对象？**
- 有方法的对象序列化后会丢失方法
- 只存数据，恢复时重新构造
- 这是 Memento 模式的思想

---

## 15. 扩展功能实现思路

### 15.1 撤销功能（Undo）

很多 2048 变体都有撤销功能。

**实现思路：**
1. 每次移动前，保存当前状态的快照
2. 按撤销键时，恢复上一个快照
3. 可以做多级撤销（保存历史数组）

**代码大致结构：**
```js
// 在 GameManager 中添加
this.history = [];
this.maxHistory = 5;  // 最多撤销 5 步

// 移动前保存
this.history.push(this.serialize());
if (this.history.length > this.maxHistory) {
    this.history.shift();  // 超过限制，删掉最早的
}

// 撤销
GameManager.prototype.undo = function() {
    if (this.history.length === 0) return;
    var prev = this.history.pop();
    // 从 prev 恢复状态
    this.grid = new Grid(prev.grid.size, prev.grid.cells);
    this.score = prev.score;
    this.over = prev.over;
    this.won = prev.won;
    this.actuate();
};
```

**注意事项：**
- 深拷贝问题：要确保保存的是完整的副本，不是引用
- 动画问题：撤销时要不要播放动画？（通常直接跳，不需要动画）
- 最高分：撤销后最高分要不要也撤销？（通常不撤销，因为最高分是记录）

### 15.2 AI 自动玩

2048 AI 是一个经典的 AI 编程练习。

**常见算法：**

| 算法 | 难度 | 效果 | 说明 |
|------|------|------|------|
| 随机 | 极低 | 很差 | 随机选方向 |
| 贪心 | 低 | 一般 | 选得分最高的一步 |
| 极大极小 | 中 | 好 | 搜索 N 步，选最优 |
| Monte Carlo | 中 | 很好 | 随机模拟很多局，选胜率最高的 |
| 期望极大极小 | 高 | 最好 | 考虑新方块的随机性 |

**极大极小（Minimax）思路：**
```
玩家的回合（max）：选择得分最高的方向
    ↓
环境的回合（min）：新方块出现在最坏的位置
    ↓
递归搜索，直到深度限制
    ↓
用评估函数打分（单调性、平滑度、空格数、最大方块...）
```

**评估函数的常见指标：**
- 单调性（行/列是否单调递增/递减）
- 平滑度（相邻方块的差值）
- 空格子数量（越多越好）
- 最大方块的位置（角落最好）
- 最大方块的数值（越大越好）

**对于 4×4 的 2048：**
- 搜索深度 4-6 步就能玩得很好
- 基本上能稳定到 2048，甚至 4096
- JavaScript 实现的话，每步思考时间 10-100ms

### 15.3 不同尺寸的网格

原版是 4×4，但 2048 可以有各种尺寸：
- 3×3：更难（空间小）
- 5×5：更容易（空间大）
- 6×6、8×8：更简单，但玩起来更累

**修改尺寸很简单：**
```js
var game = new GameManager(5, ...);  // 5×5
```

CSS 需要对应调整格子大小和位置。

### 15.4 多人对战

2048 也可以做多人对战：
- 同一个棋盘，轮流走
- 或者各自的棋盘，比谁先到 2048
- 或者 PvP 对抗版（你走一步，对手那边加一个随机方块）

需要后端（WebSocket 实时通信），纯前端做不了。

### 15.5 排行榜

纯前端做不了真正的排行榜（玩家可以改 localStorage）。

如果要做排行榜，需要后端：
- 玩家提交分数
- 服务器验证（防止作弊）
- 显示排名

作弊验证很难完全防住（玩家可以改游戏代码），通常用"信誉系统"或者"录像验证"。

---

## 16. 附录：调试技巧和代码片段

### 16.1 控制台调试

```js
// 获取游戏实例（需要在控制台能访问到的地方保存引用）
var game = ...; 

// 设置棋盘状态（测试用）
game.grid.cells[0][0] = new Tile({x:0, y:0}, 2048);
game.actuate();

// 直接执行移动
game.move(0);  // 上
game.move(1);  // 右
game.move(2);  // 下
game.move(3);  // 左

// 查看当前分数
console.log(game.score);

// 查看最高分
console.log(game.storageManager.getBestScore());

// 清除存档
game.storageManager.clearGameState();
game.storageManager.setBestScore(0);
```

### 16.2 自动玩脚本（简单贪心版）

```js
// 贴到控制台可以自动玩（简单贪心，分数不会太高）
function autoPlay() {
    if (game.over) {
        console.log('游戏结束，最终分数：' + game.score);
        return;
    }
    
    // 试四个方向，选能移动的
    var directions = [0, 1, 2, 3];  // 上右下左
    for (var i = 0; i < directions.length; i++) {
        // 这里需要判断移动后是否有变化
        // 简单版：随机选一个方向
        game.move(Math.floor(Math.random() * 4));
    }
    
    setTimeout(autoPlay, 100);
}
autoPlay();
```

### 16.3 性能测试

```js
// 测试 10000 次移动需要多久
console.time('move');
for (var i = 0; i < 10000; i++) {
    game.move(Math.floor(Math.random() * 4));
    if (game.over) {
        game.restart();
    }
}
console.timeEnd('move');
```

### 16.4 修改方块生成概率

```js
// 改成 50% 生成 2，50% 生成 4
GameManager.prototype.addRandomTile = function () {
    if (this.grid.cellsAvailable()) {
        var value = Math.random() < 0.5 ? 2 : 4;  // 改这里
        var tile = new Tile(this.grid.randomAvailableCell(), value);
        this.grid.insertTile(tile);
    }
};
```

### 16.5 快速输的方法（测试用）

```js
// 一直按左，很快就输了
var interval = setInterval(function() {
    game.move(3);  // 左
    if (game.over) {
        clearInterval(interval);
        console.log('游戏结束');
    }
}, 50);
```

---

---

## 17. AI 算法详解

2048 是一个经典的 AI 算法测试平台。下面介绍几种常见的 AI 实现方案。

### 17.1 贪心算法（Greedy）

最简单的 AI：每一步选能拿最多分的方向。

```js
function greedyAI(game) {
    var bestScore = -1;
    var bestDir = 0;
    
    for (var dir = 0; dir < 4; dir++) {
        var testGame = cloneGame(game);
        var moved = testGame.move(dir);
        if (!moved) continue;
        if (testGame.score > bestScore) {
            bestScore = testGame.score;
            bestDir = dir;
        }
    }
    
    return bestDir;
}
```

**特点**：
- 实现最简单，速度最快
- 只看一步，鼠目寸光
- 通常只能到 512 或 1024
- 容易把自己堵死

### 17.2 期望最大化（Expectimax）

比贪心多考虑几步，并且考虑随机方块的随机性。

```
当前局面
  ↓
尝试 4 个方向 → 选择期望得分最高的
  ↑           ↑
  └─ 每个方向后：
     随机生成 2 或 4（概率加权）
       ↓
     再递归评估后续几步
```

```js
function expectimax(game, depth, isPlayerTurn) {
    if (depth === 0 || game.over) {
        return evaluate(game);  // 评估函数
    }
    
    if (isPlayerTurn) {
        // 玩家回合：选最好的方向
        var best = -Infinity;
        for (var dir = 0; dir < 4; dir++) {
            var newGame = cloneGame(game);
            if (!newGame.move(dir)) continue;
            best = Math.max(best, expectimax(newGame, depth - 1, false));
        }
        return best;
    } else {
        // 随机回合：计算期望（加权平均）
        var emptyCells = game.grid.availableCells();
        var total = 0;
        
        for (var i = 0; i < emptyCells.length; i++) {
            var cell = emptyCells[i];
            // 90% 概率生成 2
            var game2 = cloneGame(game);
            game2.grid.insertTile(new Tile(cell, 2));
            total += 0.9 * expectimax(game2, depth - 1, true);
            
            // 10% 概率生成 4
            var game4 = cloneGame(game);
            game4.grid.insertTile(new Tile(cell, 4));
            total += 0.1 * expectimax(game4, depth - 1, true);
        }
        
        return total / emptyCells.length;
    }
}
```

**关键——评估函数（Heuristic）**：
评估函数的好坏直接决定 AI 水平。常用特征：

| 特征 | 说明 | 权重（参考） |
|------|------|-------------|
| 最大方块 | 越大越好 | 正权重 |
| 空格数量 | 越多越灵活 | 正权重 |
| 单调性 | 行列递增/递减 | 正权重 |
| 平滑度 | 相邻方块差值小 | 负权重（差值大扣分） |
| 角落策略 | 最大方块在角落 | 正权重 |

```js
function evaluate(game) {
    var score = 0;
    
    // 1. 空格数（越多越好）
    var empty = game.grid.availableCells().length;
    score += empty * 100;
    
    // 2. 单调性（行/列递增）
    score += monotonicity(game) * 50;
    
    // 3. 平滑度（相邻差值小）
    score -= smoothness(game) * 10;
    
    // 4. 最大方块值
    var maxTile = getMaxTile(game);
    score += maxTile * 2;
    
    return score;
}
```

**特点**：
- 考虑了随机性（期望）
- 深度 4-6 层就能玩得不错
- 通常能到 2048，甚至 4096
- 比贪心聪明，但计算量大

### 17.3 Minimax 算法

经典的博弈算法，但 2048 是单人游戏，把"随机"当成对手：

```
Max 层（玩家）：选最好的方向
  ↓
Min 层（随机）：选最差的生成位置（保守估计）
  ↓
Max 层（玩家）：...
```

和 Expectimax 的区别：
- Expectimax：随机层取**期望**（平均值）
- Minimax：随机层取**最小值**（最坏情况）

Minimax 更保守，不容易死，但得分可能低一些。

### 17.4 蒙特卡洛树搜索（MCTS）

不评估局面，而是随机玩很多次，看哪个方向赢的概率高。

```
1. 选择（Selection）：从根节点选一个最有希望的节点
2. 扩展（Expansion）：扩展一个新节点
3. 模拟（Simulation）：从这个节点随机玩到结束
4. 回溯（Backpropagation）：更新路径上所有节点的统计
```

```
每次选方向后：
  模拟 N 次随机游戏
  统计最终得分/是否到达 2048
  选胜率最高的方向
```

**特点**：
- 不需要评估函数（零知识）
- 模拟次数越多越准
- 计算量大，需要优化
- 理论上可以达到最优策略（只要模拟够多）

### 17.5 AI 性能对比

| 算法 | 实现难度 | 速度 | 最高分 | 特点 |
|------|---------|------|--------|------|
| 随机 | ★ | 极快 | ~128 | 纯碰运气 |
| 贪心 | ★ | 极快 | ~512 | 鼠目寸光 |
| 贪心+评估 | ★★ | 很快 | ~1024 | 简单有效 |
| Expectimax 深度4 | ★★★ | 中等 | 2048+ | 主流方案 |
| MCTS | ★★★★ | 慢 | 2048+ | 不需要启发函数 |
| 最优解 | ★★★★★ | 极慢 | 32768+ | 理论极限 |

> **世界纪录**：2048 的理论最大方块是 131072（17 次方），但几乎不可能达到。人类高手通常能到 4096 或 8192。

---

## 18. 数学原理与游戏理论

### 18.1 2048 是 NP 难的吗？

**结论**：2048 的决策问题（能否到达 2^n）是 NP 难的。

2014 年的论文《How to Make 2048 Harder》证明了：
- 广义 2048（n×n 棋盘）属于 NP 难问题
- 意味着没有多项式时间的最优算法
- 只能用启发式方法（heuristics）近似最优

但 4×4 的 2048 状态空间虽然大，还是可以用一些技巧优化的。

### 18.2 状态空间大小

4×4 棋盘，每个格子可以是空的，或者是 2, 4, 8, ..., 最大方块。

**理论状态数**：
- 每个格子有 12 种可能（空 + 2^1 到 2^11 = 2048）
- 12^16 ≈ 1.8 × 10^17 种状态

**实际可达状态**少得多：
- 方块总和必须是 2 的倍数
- 很多组合不可能出现
- 实际估计约 10^12 量级

还是太大了，无法穷举。

### 18.3 马尔可夫决策过程（MDP）

2048 可以建模为一个 MDP：

| MDP 要素 | 2048 对应 |
|---------|----------|
| 状态 S | 棋盘局面 |
| 动作 A | 上/下/左/右（4个方向） |
| 转移 P | 移动后随机生成新方块 |
| 奖励 R | 合并得分 |
| 策略 π | 选择动作的函数 |

目标：最大化长期累积奖励（总得分）。

**最优策略**：理论上可以用动态规划求解，但状态空间太大，只能近似。

### 18.4 熵与不确定性

每次移动后随机生成一个方块（位置和数值都是随机的），这是游戏的不确定性来源。

**为什么 10% 概率生成 4 而不是 50%？**
- 如果 50% 生成 4，游戏会简单很多
- 10% 的 4 增加了不确定性，但又不会太离谱
- 平衡了难度和可玩性

**信息论角度**：
- 生成位置：log2(空格数) 比特信息
- 生成数值：约 0.47 比特（90%/10% 的熵）
- 每次移动的不确定性 ≈ log2(N) + 0.47 比特

### 18.5 最优策略的结构

虽然无法计算精确最优解，但经验上最优策略有这些特征：

1. **角落策略**：最大的方块放在角落（通常是左下角或右下角）
2. **蛇形排列**：方块按从大到小蛇形排列在边上
3. **保持单调性**：每行每列单调递增/递减
4. **留空格**：保持足够的空格应对变化

```
角落策略示意（最大块在左下角）：
┌────┬────┬────┬────┐
│  8 │ 16 │ 32 │ 64 │
├────┼────┼────┼────┤
│  4 │  2 │  4 │  8 │
├────┼────┼────┼────┤
│  2 │    │  2 │  4 │
├────┼────┼────┼────┤
│ 2048│1024│512 │256 │  ← 最大的一排在底部
└────┴────┴────┴────┘
```

---

## 19. 不同实现方案对比

### 19.1 DOM 版（本项目）

本项目用的是 DOM + CSS3 方案。

**优点**：
- 代码简单，易理解
- CSS transition 动画方便
- 可以直接用 CSS 类控制样式
- 调试方便（F12 看元素）

**缺点**：
- 性能一般（16 个方块没问题，但扩展到更大棋盘会卡）
- 动画受浏览器回流影响
- 不适合复杂特效

**适用场景**：
- 4×4 或 5×5 的小规模棋盘
- 对性能要求不高
- 快速原型开发

### 19.2 Canvas 2D 版

用 Canvas 2D API 绘制。

```js
// 伪代码
function draw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // 画背景格子
    for (var r = 0; r < 4; r++) {
        for (var c = 0; c < 4; c++) {
            drawTileBackground(r, c);
        }
    }
    
    // 画方块
    grid.eachCell(function(r, c, tile) {
        if (tile) drawTile(tile);
    });
}
```

**优点**：
- 性能比 DOM 好
- 可以画更复杂的特效
- 动画更流畅（自己控制每帧）

**缺点**：
- 没有 DOM 的事件系统，点击检测要自己写
- 文字渲染需要自己处理
- 代码量更大

**适用场景**：
- 更大的棋盘（6×6 以上）
- 需要复杂动画效果
- 性能敏感

### 19.3 WebGL 版

用 WebGL 渲染，性能最好。

**优点**：
- GPU 加速，性能最好
- 可以做 3D 效果、粒子等
- 1000+ 方块都没问题

**缺点**：
- 实现复杂（需要写着色器）
- 2D 游戏用 WebGL 有点杀鸡用牛刀
- 兼容性需要考虑

**适用场景**：
- 超大规模棋盘
- 3D 效果需求
- 学习 WebGL

### 19.4 性能对比

| 方案 | 4×4 性能 | 10×10 性能 | 开发量 | 特效能力 |
|------|---------|-----------|--------|---------|
| DOM | ★★★★★ | ★★ | ★ | ★★★ |
| Canvas 2D | ★★★★ | ★★★★ | ★★★ | ★★★★ |
| WebGL | ★★★★★ | ★★★★★ | ★★★★★ | ★★★★★ |

对于 4×4 的 2048，DOM 完全够用，也是最合适的选择。

### 19.5 数据结构对比

棋盘的数据结构也有多种选择：

**方案 A：二维数组（本项目用）**
```js
this.cells = [[null, null, null, null],
              [null, null, null, null],
              [null, null, null, null],
              [null, null, null, null]];
```
- 直观，易理解
- 随机访问 O(1)
- 遍历方便

**方案 B：一维数组**
```js
this.cells = new Array(16).fill(null);
// 第 r 行 c 列：cells[r * 4 + c]
```
- 内存更紧凑
- 遍历稍快
- 索引需要计算

**方案 C：位运算表示（优化用）**
```js
// 每个方块 4 位（表示 2^n 的 n），16 个格子 = 64 位
// 可以用一个整数表示整个棋盘
var board = 0;
```
- 极致性能（AI 搜索用）
- 复制棋盘 = 复制整数，极快
- 实现复杂，有最大方块限制（4 位最多到 2^15 = 32768）

AI 算法通常用位运算版本，因为要克隆大量棋盘进行搜索。

---

## 20. 位运算优化详解

位运算版本的 2048 是 AI 加速的关键技术，值得深入了解。

### 20.1 棋盘编码

每个格子用 4 位表示方块的指数（0 = 空，1 = 2，2 = 4，...，15 = 32768）。

```
棋盘（4x4）：
┌──┬──┬──┬──┐
│0 │1 │2 │3 │  ← 每格 4 位
├──┼──┼──┼──┤
│4 │5 │6 │7 │
├──┼──┼──┼──┤
│8 │9 │10│11│
├──┼──┼──┼──┤
│12│13│14│15│
└──┴──┴──┴──┘

64 位整数：
[格15][格14]...[格1][格0]
```

```js
// 编码：把棋盘转成整数
function encodeBoard(grid) {
    var board = 0;
    for (var r = 0; r < 4; r++) {
        for (var c = 0; c < 4; c++) {
            var tile = grid.cells[r][c];
            var value = tile ? Math.log2(tile.value) : 0;
            board |= value << ((r * 4 + c) * 4);
        }
    }
    return board;
}
```

### 20.2 单行移动（查表法）

最精妙的优化：预计算所有可能的单行移动结果。

一行 4 个格子，每个 4 位，共 16 位，所以有 2^16 = 65536 种可能的行。

```js
// 预计算表
var moveLeftTable = new Array(65536);
var moveRightTable = new Array(65536);

// 初始化：计算每种行状态向左移动后的结果
for (var row = 0; row < 65536; row++) {
    var result = computeRowMoveLeft(row);
    moveLeftTable[row] = result.newRow | (result.score << 16);
    // 高 16 位存得分，低 16 位存新行
}

// 使用时：直接查表
function moveLeft(board) {
    var score = 0;
    var newBoard = 0;
    for (var r = 0; r < 4; r++) {
        var row = (board >> (r * 16)) & 0xFFFF;
        var result = moveLeftTable[row];
        newBoard |= (result & 0xFFFF) << (r * 16);
        score += result >>> 16;
    }
    return { board: newBoard, score: score };
}
```

**效果**：
- 移动操作从 O(n²) 变成 O(n) 查表
- AI 搜索速度提升 10-100 倍
- 这是 Expectimax AI 能跑深的关键

### 20.3 行移动的位运算实现

不用查表，直接用位运算计算一行的移动：

```js
// 一行 4 个格子，每个 4 位，共 16 位
function moveRowLeft(row) {
    // 提取 4 个格子
    var c0 = (row >> 0) & 0xF;
    var c1 = (row >> 4) & 0xF;
    var c2 = (row >> 8) & 0xF;
    var c3 = (row >> 12) & 0xF;
    
    var score = 0;
    
    // 去除空格，压缩到左边
    // ...（比较复杂，略）
    
    // 合并相同的
    if (c0 === c1 && c0 !== 0) { c0++; c1 = c2; c2 = c3; c3 = 0; score += 1 << c0; }
    if (c1 === c2 && c1 !== 0) { c1++; c2 = c3; c3 = 0; score += 1 << c1; }
    if (c2 === c3 && c2 !== 0) { c2++; c3 = 0; score += 1 << c2; }
    
    var newRow = c0 | (c1 << 4) | (c2 << 8) | (c3 << 12);
    return { row: newRow, score: score };
}
```

**为什么查表更快？**
- 预计算一次，运行时只需要一次内存访问
- 位运算版本需要多个判断和移位
- 查表是空间换时间的经典优化

### 20.4 转置与镜像

有了向左移动，怎么实现向右、向上、向下？

答案：**转置（transpose）和镜像（mirror）**。

```
向右移动 = 镜像 → 向左移动 → 镜像
向上移动 = 转置 → 向左移动 → 转置
向下移动 = 转置 → 镜像 → 向左移动 → 镜像 → 转置
```

```js
function moveRight(board) {
    return mirror(moveLeft(mirror(board)));
}

function moveUp(board) {
    return transpose(moveLeft(transpose(board)));
}

function moveDown(board) {
    return transpose(mirror(moveLeft(mirror(transpose(board)))));
}
```

这样只需要实现一个方向的移动，其他方向通过变换得到。

---

## 21. 附录：进阶算法与数学推导

### 21.1 移动算法正确性证明

**定理**：从目标方向那一侧开始遍历，依次处理每个方块，可以保证每个方块只移动一次且合并正确。

**证明**（以向左移动为例）：

考虑一行 4 个格子 [a, b, c, d]，从左到右处理。

1. 处理第 1 个格子（最左）：它已经在最左边，不会再移动
2. 处理第 2 个格子：它左边只有第 1 个格子，要么合并到第 1 个，要么移到第 1 个右边的空位
3. 处理第 3 个格子：左边的 1、2 已经处理完毕（稳定了），第 3 个可以正确找到落脚点
4. 处理第 4 个格子：同理

归纳可得：从目标方向开始遍历，每个格子处理时，它前方的格子都已经到位，所以可以正确移动和合并。

### 21.2 最大可能方块推导

**问题**：4×4 的 2048 最大能到多少？

**答案**：理论上最大是 2^17 = 131072。

**推导**：

棋盘有 16 个格子。理想情况下（全填满，刚好可以链式合并）：
- 16 个格子，每个都是不同的幂
- 最大的是 2^16 = 65536？不对

等一下，让我们重新推导：

```
每次合并：2 个 2^n → 1 个 2^(n+1)
棋盘 16 个格子：
  16 个 2 → 8 个 4 → 4 个 8 → 2 个 16 → 1 个 32
  合并了 5 次，2^5 = 32
```

不对，这是初始 16 个 2 的情况。但游戏中会不断生成新的 2 和 4。

正确的推导：
- 棋盘填满时，最多有 16 个不同的方块
- 理想情况下：2, 4, 8, 16, ..., 2^16
- 但还需要一个新生成的方块来触发合并
- 所以最大可能是 2^16 = 65536？还是 2^17？

实际上：
- 棋盘上有 15 个方块时，生成第 16 个
- 如果刚好可以链式合并，可以合并出更大的
- 理论最大值是 2^17 = 131072
- 但这需要极其完美的局面，几乎不可能出现

**世界纪录**：人类玩家最高达到过 65536，AI 可以达到 131072。

### 21.3 期望得分计算

**问题**：平均每步得多少分？

简化分析：
- 每步生成一个新方块（90% 是 2，10% 是 4）
- 每步平均增加的值 = 0.9 × 2 + 0.1 × 4 = 2.2
- 最终所有方块的值之和 = 步数 × 2.2
- 得分 = 合并时的方块值之和

得分和方块值的关系：
- 一个 2^n 的方块，它贡献的得分是 n × 2^n？不对。

让我们算一下：
- 2 个 2 合并成 4：得 4 分
- 2 个 4 合并成 8：得 8 分
- 一个 8 是怎么来的？4 个 2 → 2 个 4 → 1 个 8
- 总得分 = 4 + 4 + 8 = 16 = 2 × 8

**规律**：一个 2^n 的方块，总得分是 (n-1) × 2^n？

验证：
- 4 (2^2)：得分 4 = (2-1) × 4 = 4 ✓
- 8 (2^3)：得分 16 = (3-1) × 8 = 16 ✓
- 16 (2^4)：得分 48？让我们算：
  - 8 个 2 → 4 个 4：4 次合并，得 4×4=16
  - 4 个 4 → 2 个 8：2 次合并，得 2×8=16
  - 2 个 8 → 1 个 16：1 次合并，得 1×16=16
  - 总得分：48 = 3 × 16 = (4-1) × 16 ✓

**公式**：一个 2^n 的方块，它贡献的总得分为 (n-1) × 2^n。

所以到达 2048 (2^11) 的最低得分是 (11-1) × 2048 = 20480 分。

实际得分会更高，因为中间会有更多合并。

### 21.4 随机游走分析

如果完全随机按方向，能走多少步？

这是一个随机游走问题，但由于合并机制，分析比较复杂。

经验数据（模拟 10000 次）：
- 随机策略：平均约 30 步游戏结束
- 最大方块：通常到 16 或 32
- 得分：约 100-200 分

这也说明了策略的重要性——好的策略比随机强几十倍。

### 21.5 复杂度分析

| 操作 | 时间复杂度 | 空间复杂度 |
|------|-----------|-----------|
| 一次移动 | O(n²) | O(n²) |
| 判断游戏结束 | O(n²) | O(1) |
| 生成随机方块 | O(n²)（找空格） | O(1) |
| 贪心 AI（1步） | O(n²) | O(n²) |
| Expectimax（深度d） | O(4^d × n² × k) | O(d × n²) |

其中 n 是棋盘大小（4），k 是平均空格数。

对于 4×4 棋盘，n=4 很小，所以什么算法都很快。
瓶颈主要在 AI 搜索的深度和分支因子。

---

## 22. 完整复刻部署指南（从零到上线不出错）

> 本章手把手教你从零实现一个 2048 游戏，每步都有验证点，确保不出错。

### 22.1 前置准备

**你需要准备的东西**：

| 项目 | 要求 | 说明 |
|------|------|------|
| 文本编辑器 | VS Code / Sublime / 记事本 | 写代码 |
| 浏览器 | Chrome / Firefox / Edge | 测试用 |
| 本地 HTTP 服务器 | 可选（Python 3 等） | 本地测试用，2048 纯静态 file:// 也能跑 |
| 静态文件托管 | 任意 | GitHub Pages / InfinityFree / Vercel 等 |

**2048 是所有游戏里最简单的**：
- 纯前端，不需要后端
- 不需要构建工具
- 不需要任何库
- 几个文件就能跑

### 22.2 第一步：搭建项目结构

```
2048/
├── index.html          # 主页面
├── style.css           # 样式
├── js/
│   ├── game.js         # 游戏主逻辑
│   ├── grid.js         # 网格数据结构
│   ├── tile.js         # 方块类
│   └── storage.js      # 本地存储
└── font/
    └── ClearSans.woff  # 字体（可选，用系统字体也行）
```

先创建最小化版本：

```
2048/
├── index.html
└── style.css
```

### 22.3 第二步：HTML 骨架

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>2048</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="heading">
            <h1 class="title">2048</h1>
            <div class="scores-container">
                <div class="score-container">
                    <div class="score-title">得分</div>
                    <div id="score">0</div>
                </div>
                <div class="best-container">
                    <div class="score-title">最高</div>
                    <div id="best">0</div>
                </div>
            </div>
        </div>
        
        <div class="above-game">
            <p class="game-intro">合并相同数字，到达 <strong>2048</strong>！</p>
            <a class="restart-btn" id="restart-btn">重新开始</a>
        </div>
        
        <div class="game-container">
            <div class="grid-container">
                <!-- 16 个背景格子 -->
                <div class="grid-row">
                    <div class="grid-cell"></div>
                    <div class="grid-cell"></div>
                    <div class="grid-cell"></div>
                    <div class="grid-cell"></div>
                </div>
                <!-- ... 共 4 行 -->
            </div>
            <div class="tile-container" id="tile-container">
                <!-- 方块动态生成在这里 -->
            </div>
            
            <!-- 游戏结束遮罩 -->
            <div class="game-over" id="game-over" style="display: none;">
                <p class="game-over-text">游戏结束!</p>
                <a class="restart-btn" id="try-again-btn">再试一次</a>
            </div>
            
            <!-- 胜利遮罩 -->
            <div class="game-won" id="game-won" style="display: none;">
                <p class="game-won-text">你赢了!</p>
                <a class="continue-btn" id="continue-btn">继续玩</a>
            </div>
        </div>
        
        <p class="game-explanation">
            <strong>玩法：</strong>使用 <strong>方向键</strong> 或 <strong>滑动</strong> 移动方块。
            相同数字的方块相撞时会合并成一个！
        </p>
    </div>
    
    <script src="js/tile.js"></script>
    <script src="js/grid.js"></script>
    <script src="js/storage.js"></script>
    <script src="js/game.js"></script>
</body>
</html>
```

**✅ 验证点 1**：
- 浏览器打开 `index.html`
- 看到标题、分数区域、游戏说明
- 4×4 的背景格子
- 没有 JS 报错（虽然还没写 JS）

### 22.4 第三步：CSS 样式

```css
/* style.css */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: "Clear Sans", "Helvetica Neue", Arial, sans-serif;
    background: #faf8ef;
    color: #776e65;
    padding: 20px;
}

.container {
    width: 500px;
    margin: 0 auto;
}

.heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.title {
    font-size: 80px;
    font-weight: bold;
}

.scores-container {
    display: flex;
    gap: 10px;
}

.score-container, .best-container {
    background: #bbada0;
    color: white;
    padding: 10px 20px;
    border-radius: 6px;
    text-align: center;
    min-width: 80px;
}

.score-title {
    font-size: 12px;
    text-transform: uppercase;
    color: #eee4da;
}

#score, #best {
    font-size: 24px;
    font-weight: bold;
}

.above-game {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.game-intro {
    font-size: 16px;
}

.restart-btn, .continue-btn {
    background: #8f7a66;
    color: #f9f6f2;
    padding: 10px 20px;
    border-radius: 6px;
    text-decoration: none;
    cursor: pointer;
    font-weight: bold;
}

.restart-btn:hover, .continue-btn:hover {
    background: #9f8b77;
}

/* 游戏区域 */
.game-container {
    position: relative;
    background: #bbada0;
    border-radius: 6px;
    padding: 15px;
    width: 500px;
    height: 500px;
}

.grid-container {
    position: absolute;
    top: 15px;
    left: 15px;
    right: 15px;
    bottom: 15px;
}

.grid-row {
    display: flex;
    margin-bottom: 15px;
}

.grid-row:last-child {
    margin-bottom: 0;
}

.grid-cell {
    flex: 1;
    background: rgba(238, 228, 218, 0.35);
    border-radius: 4px;
    margin-right: 15px;
    height: 106.25px;  /* (500 - 15*2 - 15*3) / 4 */
}

.grid-cell:last-child {
    margin-right: 0;
}

/* 方块容器 */
.tile-container {
    position: absolute;
    top: 15px;
    left: 15px;
    right: 15px;
    bottom: 15px;
}

.tile {
    position: absolute;
    width: 106.25px;
    height: 106.25px;
    border-radius: 4px;
    background: #eee4da;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 45px;
    font-weight: bold;
    color: #776e65;
    transition: transform 0.15s ease-in-out;
    z-index: 10;
}

/* 不同数字的颜色 */
.tile-2    { background: #eee4da; color: #776e65; }
.tile-4    { background: #ede0c8; color: #776e65; }
.tile-8    { background: #f2b179; color: #f9f6f2; }
.tile-16   { background: #f59563; color: #f9f6f2; }
.tile-32   { background: #f67c5f; color: #f9f6f2; }
.tile-64   { background: #f65e3b; color: #f9f6f2; }
.tile-128  { background: #edcf72; color: #f9f6f2; font-size: 40px; }
.tile-256  { background: #edcc61; color: #f9f6f2; font-size: 40px; }
.tile-512  { background: #edc850; color: #f9f6f2; font-size: 40px; }
.tile-1024 { background: #edc53f; color: #f9f6f2; font-size: 32px; }
.tile-2048 { background: #edc22e; color: #f9f6f2; font-size: 32px; }

/* 位置类：通过 transform 定位 */
.tile-position-0-0 { transform: translate(0, 0); }
.tile-position-0-1 { transform: translate(121.25px, 0); }
.tile-position-0-2 { transform: translate(242.5px, 0); }
.tile-position-0-3 { transform: translate(363.75px, 0); }
.tile-position-1-0 { transform: translate(0, 121.25px); }
.tile-position-1-1 { transform: translate(121.25px, 121.25px); }
.tile-position-1-2 { transform: translate(242.5px, 121.25px); }
.tile-position-1-3 { transform: translate(363.75px, 121.25px); }
.tile-position-2-0 { transform: translate(0, 242.5px); }
.tile-position-2-1 { transform: translate(121.25px, 242.5px); }
.tile-position-2-2 { transform: translate(242.5px, 242.5px); }
.tile-position-2-3 { transform: translate(363.75px, 242.5px); }
.tile-position-3-0 { transform: translate(0, 363.75px); }
.tile-position-3-1 { transform: translate(121.25px, 363.75px); }
.tile-position-3-2 { transform: translate(242.5px, 363.75px); }
.tile-position-3-3 { transform: translate(363.75px, 363.75px); }

/* 新生成动画 */
.tile-new {
    animation: appear 0.2s ease;
}

@keyframes appear {
    0% {
        transform: scale(0);
    }
    100% {
        transform: scale(1);
    }
}

/* 合并动画 */
.tile-merged {
    animation: pop 0.2s ease;
    z-index: 20;
}

@keyframes pop {
    0% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.2);
    }
    100% {
        transform: scale(1);
    }
}

/* 游戏结束 / 胜利遮罩 */
.game-over, .game-won {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(238, 228, 218, 0.73);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    z-index: 100;
}

.game-over-text, .game-won-text {
    font-size: 60px;
    font-weight: bold;
    margin-bottom: 30px;
}

.game-explanation {
    margin-top: 30px;
    font-size: 14px;
    line-height: 1.6;
}

/* 移动端适配 */
@media screen and (max-width: 520px) {
    .container {
        width: 100%;
    }
    
    .title {
        font-size: 50px;
    }
    
    .game-container {
        width: 100%;
        height: auto;
        padding-bottom: 100%;
    }
    
    /* 移动端需要重新计算位置，这里简化处理 */
}
```

**✅ 验证点 2**：
- 页面样式正确
- 4×4 格子显示正确
- 颜色和原版 2048 一致
- 标题、分数区域布局正确
- 响应式布局在移动端也正常

### 22.5 第四步：实现数据结构（Tile + Grid）

**js/tile.js**：
```js
function Tile(position, value) {
    this.x = position.x;
    this.y = position.y;
    this.value = value || 2;
    this.previousPosition = null;
    this.mergedFrom = null;  // 记录合并来源（用于动画）
}

Tile.prototype.savePosition = function() {
    this.previousPosition = { x: this.x, y: this.y };
};

Tile.prototype.updatePosition = function(position) {
    this.x = position.x;
    this.y = position.y;
};
```

**js/grid.js**：
```js
function Grid(size) {
    this.size = size;
    this.cells = [];
    this.build();
}

// 构建空网格
Grid.prototype.build = function() {
    for (var x = 0; x < this.size; x++) {
        var row = this.cells[x] = [];
        for (var y = 0; y < this.size; y++) {
            row.push(null);
        }
    }
};

// 找随机空位
Grid.prototype.randomAvailableCell = function() {
    var cells = this.availableCells();
    if (cells.length) {
        return cells[Math.floor(Math.random() * cells.length)];
    }
};

// 获取所有空位
Grid.prototype.availableCells = function() {
    var cells = [];
    this.eachCell(function(x, y, tile) {
        if (!tile) {
            cells.push({ x: x, y: y });
        }
    });
    return cells;
};

// 遍历所有格子
Grid.prototype.eachCell = function(callback) {
    for (var x = 0; x < this.size; x++) {
        for (var y = 0; y < this.size; y++) {
            callback(x, y, this.cells[x][y]);
        }
    }
};

// 是否还有空位
Grid.prototype.cellsAvailable = function() {
    return !!this.availableCells().length;
};

// 指定位置是否可用
Grid.prototype.cellAvailable = function(cell) {
    return !this.cellContent(cell);
};

// 获取指定位置的方块
Grid.prototype.cellContent = function(cell) {
    if (this.withinBounds(cell)) {
        return this.cells[cell.x][cell.y];
    } else {
        return null;
    }
};

// 插入方块
Grid.prototype.insertTile = function(tile) {
    this.cells[tile.x][tile.y] = tile;
};

// 移除方块
Grid.prototype.removeTile = function(tile) {
    this.cells[tile.x][tile.y] = null;
};

// 检查坐标是否在边界内
Grid.prototype.withinBounds = function(position) {
    return position.x >= 0 && position.x < this.size &&
           position.y >= 0 && position.y < this.size;
};
```

**✅ 验证点 3**：
- 在控制台测试：`var g = new Grid(4)`
- `g.cells` 是 4×4 的二维数组，全是 null
- `g.randomAvailableCell()` 返回一个随机空位
- `g.cellsAvailable()` 返回 true

### 22.6 第五步：实现核心移动算法

这是 2048 最关键的部分。

**js/game.js**（核心移动逻辑）：
```js
function GameManager(size) {
    this.size = size;
    this.grid = new Grid(size);
    this.score = 0;
    this.over = false;
    this.won = false;
    this.keepPlaying = false;
    
    this.start();
}

// 开始游戏
GameManager.prototype.start = function() {
    this.grid = new Grid(this.size);
    this.score = 0;
    this.over = false;
    this.won = false;
    this.keepPlaying = false;
    
    // 初始生成 2 个方块
    this.addRandomTile();
    this.addRandomTile();
    
    this.actuate();  // 更新显示
};

// 随机生成一个新方块
GameManager.prototype.addRandomTile = function() {
    if (this.grid.cellsAvailable()) {
        var value = Math.random() < 0.9 ? 2 : 4;  // 90% 生成 2，10% 生成 4
        var tile = new Tile(this.grid.randomAvailableCell(), value);
        this.grid.insertTile(tile);
    }
};

// 移动
// direction: 0=上, 1=右, 2=下, 3=左
GameManager.prototype.move = function(direction) {
    if (this.over && !this.keepPlaying) return false;
    
    var self = this;
    var moved = false;
    
    // 保存上一帧位置（用于动画）
    this.grid.eachCell(function(x, y, tile) {
        if (tile) tile.savePosition();
    });
    
    // 根据方向获取遍历顺序
    var traversals = this.buildTraversals(direction);
    var vector = this.getVector(direction);
    
    // 遍历每一行/列
    traversals.x.forEach(function(x) {
        traversals.y.forEach(function(y) {
            var cell = { x: x, y: y };
            var tile = self.grid.cellContent(cell);
            
            if (tile) {
                var positions = self.findFarthestPosition(cell, vector);
                var next = self.grid.cellContent(positions.next);
                
                // 如果下一个位置有相同的方块，就合并
                if (next && next.value === tile.value && !next.mergedFrom) {
                    var merged = new Tile(positions.next, tile.value * 2);
                    merged.mergedFrom = [tile, next];
                    
                    self.grid.insertTile(merged);
                    self.grid.removeTile(tile);
                    
                    // 更新位置（用于动画）
                    tile.updatePosition(positions.next);
                    
                    // 加分
                    self.score += merged.value;
                    
                    // 检查是否达到 2048
                    if (merged.value === 2048 && !self.won) {
                        self.won = true;
                    }
                } else {
                    // 只是移动
                    self.moveTile(tile, positions.farthest);
                }
                
                if (!self.positionsEqual(cell, tile)) {
                    moved = true;
                }
            }
        });
    });
    
    if (moved) {
        this.addRandomTile();
        
        if (!this.movesAvailable()) {
            this.over = true;
        }
        
        this.actuate();
    }
    
    return moved;
};

// 获取方向向量
GameManager.prototype.getVector = function(direction) {
    var map = {
        0: { x: 0,  y: -1 },  // 上
        1: { x: 1,  y: 0 },   // 右
        2: { x: 0,  y: 1 },   // 下
        3: { x: -1, y: 0 }    // 左
    };
    return map[direction];
};

// 构建遍历顺序（从目标方向那一侧开始）
GameManager.prototype.buildTraversals = function(direction) {
    var traversals = { x: [], y: [] };
    
    for (var pos = 0; pos < this.size; pos++) {
        traversals.x.push(pos);
        traversals.y.push(pos);
    }
    
    // 向右或向下时，从大的坐标开始遍历
    if (direction === 1) traversals.x = traversals.x.reverse();
    if (direction === 2) traversals.y = traversals.y.reverse();
    
    return traversals;
};

// 找到方块能移动到的最远位置
GameManager.prototype.findFarthestPosition = function(cell, vector) {
    var previous;
    
    // 一直往前走，直到碰到东西
    do {
        previous = cell;
        cell = { x: previous.x + vector.x, y: previous.y + vector.y };
    } while (this.grid.withinBounds(cell) && this.grid.cellAvailable(cell));
    
    return {
        farthest: previous,
        next: cell  // 下一个位置（可能有方块）
    };
};

// 移动方块
GameManager.prototype.moveTile = function(tile, cell) {
    this.grid.cells[tile.x][tile.y] = null;
    this.grid.cells[cell.x][cell.y] = tile;
    tile.updatePosition(cell);
};

// 检查位置是否相同
GameManager.prototype.positionsEqual = function(first, second) {
    return first.x === second.x && first.y === second.y;
};

// 检查是否还能移动
GameManager.prototype.movesAvailable = function() {
    return this.grid.cellsAvailable() || this.tileMatchesAvailable();
};

// 检查是否有可合并的相邻方块
GameManager.prototype.tileMatchesAvailable = function() {
    var self = this;
    var tile;
    
    for (var x = 0; x < this.size; x++) {
        for (var y = 0; y < this.size; y++) {
            tile = this.grid.cellContent({ x: x, y: y });
            if (tile) {
                // 检查四个方向
                for (var direction = 0; direction < 4; direction++) {
                    var vector = self.getVector(direction);
                    var other = self.grid.cellContent({
                        x: x + vector.x,
                        y: y + vector.y
                    });
                    if (other && other.value === tile.value) {
                        return true;
                    }
                }
            }
        }
    }
    return false;
};

// 更新显示（交给 UI 层实现）
GameManager.prototype.actuate = function() {
    // 由 UI 层重写这个方法
    console.log('Score:', this.score);
    console.log('Grid:', this.grid.cells);
};
```

**✅ 验证点 4**：
- 在控制台测试：`var game = new GameManager(4)`
- `game.move(0)` 向上移动
- `game.move(1)` 向右移动
- 方块正确移动和合并
- 分数正确累加
- 移动后生成新方块
- 填满后移动不了返回 false

### 22.7 第六步：UI 渲染和事件绑定

在 `game.js` 末尾添加 UI 层：

```js
// DOM 元素
var tileContainer = document.getElementById('tile-container');
var scoreDisplay = document.getElementById('score');
var bestDisplay = document.getElementById('best');
var gameOverMsg = document.getElementById('game-over');
var gameWonMsg = document.getElementById('game-won');
var restartBtn = document.getElementById('restart-btn');
var tryAgainBtn = document.getElementById('try-again-btn');
var continueBtn = document.getElementById('continue-btn');

// 最高分
var bestScore = parseInt(localStorage.getItem('bestScore') || '0', 10);
bestDisplay.textContent = bestScore;

// 重写 actuate 方法
GameManager.prototype.actuate = function() {
    // 更新分数
    scoreDisplay.textContent = this.score;
    
    // 更新最高分
    if (this.score > bestScore) {
        bestScore = this.score;
        bestDisplay.textContent = bestScore;
        localStorage.setItem('bestScore', String(bestScore));
    }
    
    // 清除旧方块
    tileContainer.innerHTML = '';
    
    // 渲染所有方块
    var self = this;
    this.grid.eachCell(function(x, y, tile) {
        if (tile) {
            var element = document.createElement('div');
            element.classList.add('tile');
            element.classList.add('tile-' + tile.value);
            element.classList.add('tile-position-' + y + '-' + x);
            
            // 新生成的方块
            if (!tile.previousPosition) {
                element.classList.add('tile-new');
            }
            
            // 合并的方块
            if (tile.mergedFrom) {
                element.classList.add('tile-merged');
            }
            
            element.textContent = tile.value;
            tileContainer.appendChild(element);
        }
    });
    
    // 游戏结束
    if (this.over) {
        gameOverMsg.style.display = 'flex';
    } else {
        gameOverMsg.style.display = 'none';
    }
    
    // 胜利
    if (this.won && !this.keepPlaying) {
        gameWonMsg.style.display = 'flex';
    }
};

// 键盘事件
document.addEventListener('keydown', function(e) {
    var map = {
        38: 0,  // 上
        39: 1,  // 右
        40: 2,  // 下
        37: 3   // 左
    };
    
    if (e.keyCode in map) {
        e.preventDefault();
        game.move(map[e.keyCode]);
    }
});

// 触摸事件（简化版）
var touchStartX = 0;
var touchStartY = 0;

document.addEventListener('touchstart', function(e) {
    touchStartX = e.touches[0].clientX;
    touchStartY = e.touches[0].clientY;
}, { passive: true });

document.addEventListener('touchend', function(e) {
    var dx = e.changedTouches[0].clientX - touchStartX;
    var dy = e.changedTouches[0].clientY - touchStartY;
    
    var absDx = Math.abs(dx);
    var absDy = Math.abs(dy);
    
    if (Math.max(absDx, absDy) < 20) return;  // 滑动距离太短
    
    if (absDx > absDy) {
        // 水平滑动
        game.move(dx > 0 ? 1 : 3);  // 右 or 左
    } else {
        // 垂直滑动
        game.move(dy > 0 ? 2 : 0);  // 下 or 上
    }
}, { passive: true });

// 按钮事件
restartBtn.addEventListener('click', restart);
tryAgainBtn.addEventListener('click', restart);

function restart() {
    gameOverMsg.style.display = 'none';
    gameWonMsg.style.display = 'none';
    game.start();
}

continueBtn.addEventListener('click', function() {
    gameWonMsg.style.display = 'none';
    game.keepPlaying = true;
});

// 启动游戏
var game = new GameManager(4);
```

**✅ 验证点 5**：
- 页面上显示 2 个初始方块
- 方向键可以移动方块
- 相同数字合并
- 分数正确显示
- 最高分保存（刷新页面还在）
- 游戏结束时显示提示
- 触摸滑动也能玩
- 重新开始按钮工作正常

### 22.8 第七步：本地存储模块

把存储逻辑抽出来，方便维护。

**js/storage.js**：
```js
function StorageManager() {
    this.gameStateKey = 'gameState';
    this.bestScoreKey = 'bestScore';
}

StorageManager.prototype.getGameState = function() {
    var state = localStorage.getItem(this.gameStateKey);
    return state ? JSON.parse(state) : null;
};

StorageManager.prototype.setGameState = function(state) {
    localStorage.setItem(this.gameStateKey, JSON.stringify(state));
};

StorageManager.prototype.clearGameState = function() {
    localStorage.removeItem(this.gameStateKey);
};

StorageManager.prototype.getBestScore = function() {
    return parseInt(localStorage.getItem(this.bestScoreKey) || '0', 10);
};

StorageManager.prototype.setBestScore = function(score) {
    localStorage.setItem(this.bestScoreKey, String(score));
};
```

然后在游戏中使用：
```js
var storage = new StorageManager();

// 保存游戏状态
GameManager.prototype.save = function() {
    storage.setGameState({
        grid: this.grid.serialize(),
        score: this.score,
        over: this.over,
        won: this.won,
        keepPlaying: this.keepPlaying
    });
};

// 在 actuate 中调用 save
```

**✅ 验证点 6**：
- 玩一半刷新页面，游戏状态还在
- 最高分保存正常
- 清除缓存后状态重置

### 22.9 第八步：完整测试

**功能测试清单**：

- [ ] 初始有 2 个方块
- [ ] 方向键上/下/左/右都能移动
- [ ] 相同数字合并正确
- [ ] 合并得分正确
- [ ] 新方块生成在空位
- [ ] 90% 生成 2，10% 生成 4
- [ ] 游戏结束判定正确
- [ ] 胜利判定正确（到达 2048）
- [ ] 继续玩功能正常
- [ ] 重新开始功能正常
- [ ] 最高分保存正常
- [ ] 游戏进度保存正常（刷新还在）
- [ ] 移动端触摸滑动正常
- [ ] 动画流畅

**性能测试**：
- 连续玩 100 步不卡
- 内存没有持续增长
- 动画流畅（CSS transition）

### 22.10 第九步：部署上线

**上传文件清单**：

| 文件 | 必传 | 说明 |
|------|------|------|
| `index.html` | ✅ | 主页面 |
| `style.css` | ✅ | 样式 |
| `js/game.js` | ✅ | 游戏主逻辑 |
| `js/grid.js` | ✅ | 网格数据结构 |
| `js/tile.js` | ✅ | 方块类 |
| `js/storage.js` | ✅ | 本地存储 |
| `font/ClearSans.woff` | ⭕ | 字体（可选，不用也没事） |

**部署方式**：任何静态托管都行。

**部署步骤**：
1. 上传所有文件
2. 访问线上地址
3. 按测试清单逐项检查

**✅ 验证点 7**：
- 线上地址能正常访问
- 所有功能和本地一致
- 控制台没有报错

### 22.11 完整错误速查表

| 错误现象 | 可能原因 | 精确修复步骤 |
|---------|---------|-------------|
| 方块不显示 | JS 报错 / DOM 没生成 | 1. F12 看 Console 错误<br>2. 检查 tile-container 里有没有元素<br>3. 确认 JS 文件加载顺序正确 |
| 移动没反应 | 事件没绑定 / 游戏结束了 | 1. 检查 keydown 事件有没有触发<br>2. 确认 `game.over` 是 false<br>3. 控制台手动调用 `game.move(0)` 试试 |
| 方块重叠了 | 移动算法有 bug | 1. 检查 `moveTile` 是否正确移除旧位置<br>2. 检查合并逻辑是否正确处理<br>3. 检查 `mergedFrom` 标记有没有重置 |
| 合并后分数不对 | 加分逻辑有问题 | 1. 确认每次合并加的是合并后的值<br>2. 确认一次移动中多次合并都加了<br>3. 检查是不是加了双倍 |
| 游戏不会结束 | `movesAvailable` 判断错了 | 1. 检查是否还有空位<br>2. 检查相邻方块是否有相同的<br>3. 边界格子不要越界检查 |
| 最高分保存不了 | localStorage 禁用 / 键名错 | 1. F12 → Application → Local Storage 查看<br>2. 检查键名拼写<br>3. 确认不是无痕模式 |
| 刷新后游戏没了 | 没保存游戏状态 | 1. 确认每次移动后都调用了 save<br>2. 确认启动时读取了存档<br>3. 检查序列化/反序列化是否正确 |
| 动画卡顿 | CSS transition 被回流影响 | 1. 用 transform 而不是 left/top<br>2. 减少每次移动的 DOM 操作<br>3. 用 will-change: transform 优化 |
| 移动端滑动不准 | 触摸判定逻辑有问题 | 1. 增加最小滑动距离（20px 以上）<br>2. 处理多指触摸<br>3. 加 `touch-action: none` 防止页面滚动 |
| 方块位置不对 | CSS 位置计算错误 | 1. 检查 tile-position-x-y 的 transform 值<br>2. 确认格子大小和间距计算正确<br>3. 移动端是不是用了百分比 |
| 新方块没有出现动画 | CSS class 没加对 | 1. 检查是否加了 `tile-new` class<br>2. 检查 @keyframes appear 是否定义<br>3. 可能是 display:none 导致动画不触发 |
| 控制台报错 "is not a function" | JS 加载顺序错了 | 1. 检查 script 标签顺序<br>2. 依赖的文件要先加载<br>3. tile.js → grid.js → storage.js → game.js |

### 22.12 上线前验收清单

- [ ] 桌面端 Chrome 正常运行
- [ ] 桌面端 Firefox 正常运行
- [ ] 桌面端 Edge 正常运行
- [ ] 方向键操作正常
- [ ] 合并逻辑正确
- [ ] 得分计算正确
- [ ] 游戏结束判定正确
- [ ] 胜利判定正确
- [ ] 重新开始功能正常
- [ ] 最高分保存正常
- [ ] 游戏进度保存正常
- [ ] 移动端触摸滑动正常
- [ ] 动画流畅自然
- [ ] 控制台没有报错
- [ ] 没有 404 错误
- [ ] 响应式布局正常
- [ ] 刷新页面不丢进度

---

**文档版本**：v1.3（完整复刻版）
**最后更新**：2026-10-05
**更新内容**：新增完整复刻部署指南（12 个步骤+验证点）、完整错误速查表（12 种常见错误）、上线前验收清单
