# Paper Glider 技术文档

**游戏名称**：Paper Glider: Fly a Paper Airplane Through Endless Rooms
**类型**：3D 网页游戏（Three.js + WebGL）
**技术方案**：Three.js r128 + 原生 JavaScript，单文件 HTML，CDN 加载依赖
**部署方式**：纯静态文件，可部署到任何静态托管服务

---

## 目录

1. [项目概述](#1-项目概述)
2. [技术架构](#2-技术架构)
3. [Three.js 场景构建](#3-threejs-场景构建)
4. [程序化生成：房间与家具](#4-程序化生成房间与家具)
5. [纸飞机物理与操控](#5-纸飞机物理与操控)
6. [碰撞检测与得分系统](#6-碰撞检测与得分系统)
7. [性能优化策略](#7-性能优化策略)
8. [移动端适配](#8-移动端适配)
9. [部署指南](#9-部署指南)
10. [故障排查](#10-故障排查)

---

## 1. 项目概述

### 1.1 项目背景

Paper Glider 是一款 3D 纸飞机飞行游戏，玩家操控纸飞机穿越无尽的房间，穿过浮动的圆环得分，躲避家具。游戏致敬经典游戏 Glider PRO。

本项目将原始的单页游戏集成到游戏合集中，添加了返回导航按钮。

### 1.2 技术选型

| 组件 | 选择 | 原因 |
|------|------|------|
| 3D 引擎 | Three.js r128 | 最流行的 WebGL 引擎，社区活跃，CDN 资源丰富 |
| 渲染方式 | WebGL + MeshLambertMaterial | 性能与画质平衡，Lambert 材质足够表现温馨室内风格 |
| 纹理 | Canvas 程序化生成 | 不依赖外部图片资源，单文件部署 |
| 字体 | Google Fonts (Fraunces + Karla) | 高质量字体，CDN 加载 |
| 构建 | 无（单 HTML 文件） | 游戏代码量适中，单文件部署最简单 |

### 1.3 核心特性

- ✅ 无尽房间程序化生成
- ✅ 鼠标 / 触摸 / 键盘（WASD + 方向键）操控
- ✅ 圆环得分 + 连击系统
- ✅ 上帝模式（无敌）
- ✅ 最高分本地存储
- ✅ 粒子效果（尘埃、纸屑）
- ✅ 响应式布局，手机桌面都能玩
- ✅ DPR 自适应，平衡画质与性能

---

## 2. 技术架构

### 2.1 单文件架构

整个游戏只有一个 HTML 文件，包含：
- HTML 结构（页面、HUD、菜单）
- CSS 样式（内联 `<style>`）
- JavaScript 游戏逻辑（内联 `<script>`）
- Three.js 从 CDN 加载
- 字体从 Google Fonts 加载
- 纹理用 Canvas 程序化生成

**为什么用单文件？**
- 部署简单，传一个文件就行
- 没有构建工具，没有依赖管理问题
- 加载请求少，首屏快
- 代码量约 1000+ 行，单文件可维护

### 2.2 整体结构

```
┌─────────────────────────────────────────┐
│  UI 层（HTML + CSS）                     │
│  菜单 / HUD / 得分 / 设置                │
└──────────────┬──────────────────────────┘
               │ 状态同步
               ▼
┌─────────────────────────────────────────┐
│  游戏逻辑层（JS）                         │
│  状态机 / 物理 / 碰撞 / 得分              │
└──────────────┬──────────────────────────┘
               │ 渲染指令
               ▼
┌─────────────────────────────────────────┐
│  Three.js 渲染层                          │
│  Scene / Camera / Renderer / Mesh        │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│  WebGL（浏览器）                          │
│  GPU 加速渲染                             │
└─────────────────────────────────────────┘
```

### 2.3 游戏状态机

游戏用一个简单的状态机管理：

```js
let state = 'menu';  // menu | playing | crashed | over
```

| 状态 | 说明 | 切换条件 |
|------|------|---------|
| `menu` | 主菜单，飞机慢速飞行展示 | 点击/空格 → `playing` |
| `playing` | 游戏进行中 | 撞墙 → `crashed` |
| `crashed` | 刚撞毁，显示原因 | 延迟后 → `over` |
| `over` | 游戏结束，显示得分 | 点击/空格 → `playing`（重新开始） |

状态机的好处：逻辑清晰，每个状态只处理自己的输入和更新，不会互相干扰。

### 2.4 全局常量

游戏的核心参数都定义为常量，方便调优：

```js
const ROOM_W = 13, ROOM_H = 9, ROOM_L = 26;  // 房间尺寸
const BOUND_X = 4.7, BOUND_Y_LO = 0.9, BOUND_Y_HI = 7.5;  // 飞行边界
const PLANE_R = 0.34;       // 碰撞半径（比视觉小，容错）
const RING_R  = 1.7;        // 圆环碰撞半径
const SPEED_BASE = 13, SPEED_MAX = 30, SPEED_MENU = 6;  // 速度
const SPAWN_DEPTH = -96;    // 预生成房间的距离
const GRACE_TIME = 1.2;     // 起飞后无敌时间（秒）
```

这些常量是游戏手感的关键，调整它们会显著改变游戏体验。

---

## 3. Three.js 场景构建

### 3.1 渲染器初始化

```js
renderer = new THREE.WebGLRenderer({
    antialias: true,
    powerPreference: 'high-performance'
});
```

**关键配置：**
- `antialias: true`：开启抗锯齿，画面更平滑
- `powerPreference: 'high-performance'`：提示浏览器使用独立显卡（对有核显+独显的笔记本有用）

### 3.2 相机

```js
camera = new THREE.PerspectiveCamera(
    62,                        // 视场角 FOV
    window.innerWidth / window.innerHeight,  // 宽高比
    0.1,                       // 近裁剪面
    140                        // 远裁剪面
);
```

FOV 62° 是比较自然的视角，既不太宽（鱼眼畸变）也不太窄（像望远镜）。

远裁剪面 140 是因为房间只生成到 -96 的深度，再远的不需要渲染。

### 3.3 光照系统

两盏灯模拟室内自然光：

```js
// 半球光：模拟环境光（天空色 + 地面色）
const hemi = new THREE.HemisphereLight(0xfff2dc, 0xc9a97e, 1.05);

// 平行光：模拟阳光（从窗户照进来）
const sun = new THREE.DirectionalLight(0xffe3bc, 0.5);
```

**为什么用 HemisphereLight 而不是 AmbientLight？**
- AmbientLight 是均匀的，没有层次感
- HemisphereLight 天空色（上）和地面色（下）不一样，更真实
- 室内场景下，天花板和地板的反光确实不同

配合 `MeshLambertMaterial`，性能足够，效果也温馨。

### 3.4 雾效

```js
scene.fog = new THREE.Fog(0xf6e7cd, 20, 62);
```

线性雾，颜色和墙面颜色一致（暖色调），开始距离 20，结束距离 62。

雾的作用：
1. **隐藏远处的房间生成接缝**：房间生成在远处，雾遮挡住就看不到突然出现
2. **增强纵深感**：有雾才显得房间深邃
3. **性能优化**：配合远裁剪面，远处的物体可以不渲染

### 3.5 DPR 自适应

```js
let dprCap = Math.min(window.devicePixelRatio || 1, 2);
let dprNow = dprCap;

renderer.setPixelRatio(dprNow);
```

**为什么限制在 2 倍？**
- 高端手机 DPR 可能是 3 甚至更高
- DPR 越高，渲染像素越多，性能压力越大
- 人眼在手机上 2 倍和 3 倍差别不大，但性能差距 2.25 倍
- 限制在 2 倍，平衡画质和性能

如果游戏掉帧，可以进一步降低 DPR 到 1.5 或 1。

---

## 4. 程序化生成：房间与家具

### 4.1 为什么用程序化生成

无尽跑酷类游戏需要无限的关卡内容，如果手动设计每个房间：
- 工作量大
- 文件体积大
- 玩家容易腻

程序化生成（Procedural Generation）的好处：
- 代码量小，内容无限
- 每次玩都不一样，重玩价值高
- 不需要额外的美术资源

### 4.2 房间结构

每个房间是一个长方体盒子：

```
     ┌─────────────────────────┐
    /│                        /│
   / │                       / │
  ┌──┼──────────────────────┐  │
  │  │    房间内部           │  │
  │  │  (家具随机摆放)        │  │
  │  │                      │  │
  │  └──────────────────────┼──┘
  │ /                       │ /
  │/                        │/
  └─────────────────────────┘

  宽 ROOM_W = 13
  高 ROOM_H = 9
  长 ROOM_L = 26
```

每个房间包含：
- 地板、天花板、四面墙
- 门（前后墙上，让飞机穿过）
- 随机家具（桌子、书架、沙发、椅子、灯、植物等）
- 随机数量的圆环（得分目标）

### 4.3 房间池与滚动

不是每帧都生成新房间，而是用**对象池**的思路：

```
 已删除   可见区域    预生成区域
←───────  ──────────  ───────────→
  [房间] [房间][房间] [房间][房间]
          ↑
       玩家位置
```

- 玩家向前飞，穿过一个房间后，把最旧的那个房间回收
- 同时在最前面生成一个新房间
- 始终保持 N 个房间在场景中

**优点：**
- 内存稳定（不会无限增长）
- 性能稳定（渲染的物体数量固定）
- 玩家感觉是"无尽"的

### 4.4 家具生成

每种家具都是一个函数，返回一个 Three.js Group：

```js
function tTable() { /* 桌子 */ }
function tShelf() { /* 书架 */ }
function tSofa() { /* 沙发 */ }
function tChair() { /* 椅子 */ }
function tLamp()  { /* 台灯 */ }
function tPlant() { /* 植物 */ }
// ... 更多家具
```

**家具的构造方式：**
- 基本几何体拼搭（BoxGeometry、CylinderGeometry、SphereGeometry 等）
- 没有复杂的模型文件
- 用 MeshLambertMaterial 表现质感
- 颜色调色板统一（暖色、木质、布艺）

这种方式叫 **"几何体拼搭"（Kitbashing）**，用简单几何体组合出复杂物体，性能好，代码量小。

### 4.5 Canvas 程序化纹理

纸纹理和木纹不是图片，是用 Canvas 2D 画出来的：

```js
function makeCanvas(size, painter) {
    const c = document.createElement('canvas');
    c.width = c.height = size;
    const ctx = c.getContext('2d');
    painter(ctx, size);
    const tex = new THREE.CanvasTexture(c);
    return tex;
}

const paperTex = makeCanvas(256, (ctx, s) => {
    // 画随机噪点模拟纸张纤维
    for (let i = 0; i < 2600; i++) {
        const g = 235 + Math.floor(Math.random() * 20);
        ctx.fillStyle = `rgb(${g},${g-5},${g-15})`;
        ctx.fillRect(Math.random() * s, Math.random() * s, 1, 1);
    }
    // 画几条折痕
    for (let i = 0; i < 40; i++) {
        // ...
    }
});
```

**为什么不直接用图片？**
- 单文件部署，不需要额外资源
- 分辨率可控（256×256 足够）
- 每次生成略有不同，更自然
- 体积小（代码比图片小）

### 4.6 圆环（得分目标）

圆环用 TorusGeometry（圆环几何体）：

```js
ring: new THREE.TorusGeometry(1.1, 0.09, 10, 36)
```

参数：
- 主半径 1.1
- 管半径 0.09
- 径向分段 10（管的方向，少点没事）
- 环向分段 36（圆环方向，36 段够圆了）

材质用了自发光（emissive），让圆环在室内光线下也醒目：
```js
ring: new THREE.MeshLambertMaterial({
    color: '#e0704a',
    emissive: 0xb14a24,
    emissiveIntensity: 0.42
})
```

---

## 5. 纸飞机物理与操控

### 5.1 纸飞机模型

纸飞机不是加载的 3D 模型，是用代码构建的 BufferGeometry：

```js
function buildDart() {
    // 定义三角形顶点（机头、左右翼尖、机尾等关键点）
    const N  = [0, 0, -1.25];          // 机头
    const TL = [-1.05, 0.16, 0.95],    // 左翼尖
    const TR = [1.05, 0.16, 0.95],     // 右翼尖
    // ... 更多顶点
    
    const tris = [
        // 每个三角形三个顶点
        N, TL, TC,   // 左半翼上表面
        N, TC, TR,   // 右半翼上表面
        // ...
    ];
    
    // 转换成 BufferGeometry
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    // ...
    
    return mesh;
}
```

**为什么代码构建而不加载模型？**
- 纸飞机形状简单，十几个三角形就够了
- 不需要模型文件，单文件部署
- 可以精确控制每一个顶点的位置

### 5.2 操控方式

三种操控方式，自动适配：

| 设备 | 操控方式 | 实现 |
|------|---------|------|
| 桌面 | 鼠标跟随 | `mousemove` 事件，飞机朝鼠标位置飞 |
| 手机 | 触摸拖动 | `touchmove` 事件，逻辑同鼠标 |
| 键盘 | WASD / 方向键 | `keydown` / `keyup` 记录按键状态 |

**鼠标/触摸操控的原理：**
- 记录鼠标/手指的屏幕坐标
- 转换成 3D 世界中的目标位置
- 飞机朝目标位置平滑移动（lerp 插值）
- 不是直接瞬移，有加速/减速的手感

**键盘操控的原理：**
- 用对象记录每个按键的状态（按下/松开）
- 每帧根据按键状态计算速度变化
- 上下左右分别控制 Y 轴和 X 轴的移动

### 5.3 飞行物理

简化的物理模型，不是真实的空气动力学：

```js
// 速度系统
let speed = SPEED_BASE;    // 当前速度
const SPEED_BASE = 13;     // 基础速度
const SPEED_MAX = 30;      // 最大速度

// 位置更新（每帧）
plane.position.z -= speed * deltaTime;  // 向前飞
plane.position.x = lerp(plane.position.x, targetX, 0.1);  // 左右平滑
plane.position.y = lerp(plane.position.y, targetY, 0.1);  // 上下平滑
```

**物理简化：**
- Z 轴速度恒定（或缓慢加速），飞机一直向前飞
- X/Y 轴用 lerp 插值，有"惯性"的手感
- 没有真实的升力、阻力、重力（那会太难操控）

**为什么简化物理？**
- 这是休闲游戏，不是飞行模拟器
- 简化物理 = 更好操控 = 更好玩
- 真实物理反而会让玩家挫败

### 5.4 飞机姿态

飞机不是一直平飞的，会根据移动方向倾斜：

```js
// 左右倾斜（滚转）
plane.rotation.z = -(targetX - plane.position.x) * 0.3;

// 上下倾斜（俯仰）
plane.rotation.x = (targetY - plane.position.y) * 0.2;
```

飞机朝左飞就向左倾，朝上飞就抬头，给玩家视觉反馈。虽然物理是简化的，但视觉上看起来像真的在飞。

### 5.5 起飞无敌时间

```js
const GRACE_TIME = 1.2;  // 起飞后 1.2 秒无敌
let graceUntil = 0;      // 无敌结束时间

// 起飞时设置
graceUntil = playT + GRACE_TIME;

// 碰撞检测时判断
if (playT < graceUntil) return;  // 无敌期，不检测碰撞
```

**为什么需要无敌时间？**
- 刚起飞时飞机位置固定，玩家还没反应过来
- 如果刚起飞就撞家具，体验很差
- 1.2 秒足够玩家调整位置

---

## 6. 碰撞检测与得分系统

### 6.1 碰撞检测策略

不是用物理引擎（如 Cannon.js、Ammo.js），而是**简化的距离检测**：

```js
const PLANE_R = 0.34;  // 飞机碰撞半径（比视觉小，容错）
```

**为什么不用物理引擎？**
- 游戏不需要真实物理碰撞反馈
- 物理引擎太重，增加代码体积
- 简化检测更快，性能更好
- 碰撞半径设小一点，玩家感觉"擦边躲过"，体验更好

### 6.2 家具碰撞

家具碰撞用**包围盒（AABB）**检测：
- 每个家具有一个碰撞盒（比视觉小）
- 飞机是一个球（半径 PLANE_R）
- 检测球和盒子是否相交

**AABB vs 球 的碰撞算法：**
1. 找到盒子上离球心最近的点
2. 计算球心到这个点的距离
3. 如果距离 < 球半径，就碰撞了

比精确的三角形碰撞检测快得多，对于这个游戏完全够用。

### 6.3 圆环得分

圆环碰撞也是距离检测：

```js
const RING_R = 1.7;  // 圆环碰撞半径（比视觉大，容错）
```

飞机飞到圆环附近就算穿过得分。

**为什么半径比视觉大？**
- 3D 游戏中，玩家判断"有没有穿过"有误差
- 稍微放大判定范围，玩家觉得"刚好穿过"
- 如果判定太严，玩家会觉得"明明穿过了却不算"

### 6.4 连击系统

连续穿过圆环有连击加成：

```
穿过 1 个 → 1 分（1×）
连续穿过 2 个 → 2 分（2×）
连续穿过 3 个 → 3 分（3×）
...
```

撞到家具 → 连击重置为 1。

连击系统的设计目的：
- 鼓励玩家挑战（高风险高回报）
- 增加游戏深度
- 高手能刷更高分

### 6.5 最高分存储

```js
const store = {
    get: key => localStorage.getItem(key),
    set: (key, val) => localStorage.setItem(key, String(val))
};

let best = parseInt(store.get('paperglider_best') || '0', 10) || 0;
```

用 localStorage 保存最高分，键名加前缀避免和其他游戏冲突。

每次游戏结束时更新：
```js
if (score > best) {
    best = score;
    store.set('paperglider_best', best);
}
```

---

## 7. 性能优化策略

### 7.1 材质复用

所有家具共享同一套材质对象（`M` 对象），而不是每个家具创建新材质：

```js
const M = {
    floor: new THREE.MeshLambertMaterial({ color: '#e0b47c', map: woodTex }),
    wall:  new THREE.MeshLambertMaterial({ color: '#e9dcc4', map: paperTex }),
    // ...
};
```

**为什么重要？**
- Three.js 中相同材质的物体可以批量渲染（Draw Call 合并）
- 如果每个家具都 new 一个材质，Draw Call 会暴增
- 共享材质 → 更少的 Draw Call → 更流畅

### 7.2 几何体复用

同理，基本几何体也复用：

```js
const G = {
    unit: new THREE.BoxGeometry(1, 1, 1),
    floor: new THREE.PlaneGeometry(ROOM_W, ROOM_L),
    ring: new THREE.TorusGeometry(1.1, 0.09, 10, 36),
    // ...
};
```

家具用同一个 BoxGeometry，只是 scale 不同。

### 7.3 DPR 限制

前面提到过，把 DPR 限制在 2 倍以内：

```js
let dprCap = Math.min(window.devicePixelRatio || 1, 2);
renderer.setPixelRatio(dprCap);
```

### 7.4 雾 + 远裁剪面

雾效不只是视觉效果，也是性能优化：
- 远处的物体被雾遮住了，可以减少渲染距离
- 远裁剪面设 140，比实际可见距离稍远一点
- 减少需要渲染的物体数量

### 7.5 对象池

房间和家具用对象池，不频繁创建/销毁：
- 房间滚出视野后，不是删除，而是移到前面重新使用
- 减少 GC（垃圾回收）压力
- 避免卡顿（GC 停顿在游戏中很明显）

### 7.6 MeshLambertMaterial

不用更高级的材质（Standard、Physical），只用 Lambert：

| 材质 | 光照计算 | 性能 | 适用场景 |
|------|---------|------|---------|
| MeshBasicMaterial | 无光照 | 最快 | UI、自发光物体 |
| MeshLambertMaterial | 漫反射 | 快 | 大部分物体 |
| MeshStandardMaterial | PBR（金属+粗糙） | 中等 | 高质量场景 |
| MeshPhysicalMaterial | 完整 PBR | 慢 | 高精度需求 |

Lambert 对于室内温馨风格足够了，性能比 Standard 好很多。

---

## 8. 移动端适配

### 8.1 触摸操控

移动端自动切换到触摸操控：
- `touchstart` / `touchmove` / `touchend`
- 手指位置就是目标位置
- 触摸的 UI 反馈和鼠标一致

### 8.2 视口配置

```html
<meta name="viewport" content="width=device-width, initial-scale=1.0,
    maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
```

- `user-scalable=no`：禁止缩放（游戏需要滑动操控）
- `viewport-fit=cover`：iPhone 刘海屏适配
- `touch-action:none`：禁止浏览器默认触摸行为（滚动、缩放）

### 8.3 视觉视口适配

```js
if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', () => {
        window.dispatchEvent(new Event('resize'));
    });
}
```

移动端浏览器的地址栏会伸缩，`visualViewport` 能获取真正的可视区域大小，确保游戏画面始终填满可用空间。

### 8.4 性能考虑

移动端 GPU 比桌面弱很多，需要更保守的设置：
- DPR 限制在 2（前面说过）
- 粒子数量可以减少
- 家具数量可以减少

---

## 9. 部署指南

### 9.1 部署要求

- 任何静态文件托管服务
- 不需要后端、不需要数据库
- 只有一个 HTML 文件
- Three.js 从 CDN 加载

### 9.2 部署步骤

1. 把 `paper-glider/` 目录上传到服务器
2. 访问 `index.html` 即可

### 9.3 CDN 依赖

游戏依赖的外部资源：

| 资源 | 来源 | 作用 |
|------|------|------|
| Three.js r128 | cdnjs.cloudflare.com | 3D 引擎 |
| Google Fonts | fonts.googleapis.com | 字体 |
| Google Analytics | googletagmanager.com | 统计（可选） |

如果 CDN 被墙，游戏可能加载失败。可以考虑：
1. 把 Three.js 下载到本地（文件 ~600KB gzipped）
2. 字体用系统字体替代

### 9.4 缓存建议

```apache
# HTML 不缓存（方便更新）
ExpiresByType text/html "access plus 0 seconds"

# Three.js CDN 资源由 CDN 控制缓存
# 本地 CSS/JS 缓存 7 天
```

### 9.5 单文件的优势

- 上传简单（一个文件）
- 没有相对路径问题
- 不容易缺文件
- 可以直接在浏览器打开本地文件运行（file:// 协议）

---

## 10. 故障排查

### 10.1 页面空白/黑屏

**可能原因：**
1. WebGL 不支持（老浏览器/旧设备）
2. Three.js CDN 加载失败
3. JS 报错

**排查方法：**
- F12 看 Console 错误
- 检查浏览器是否支持 WebGL（https://get.webgl.org/）
- Network 看 Three.js 是否加载成功

### 10.2 游戏卡顿

**可能原因：**
1. DPR 太高（高端手机 3x DPR）
2. 浏览器 tab 在后台
3. 设备性能不够

**优化方向：**
- 降低 DPR（把 dprCap 改成 1）
- 减少家具数量
- 减少粒子数量
- 用 MeshBasicMaterial 替代 Lambert

### 10.3 触摸没反应

**可能原因：**
1. `touch-action:none` 阻止了触摸
2. UI 元素挡住了
3. 游戏在 menu 状态（需要先点开始）

### 10.4 分数不保存

**可能原因：**
1. localStorage 被禁用
2. 无痕/隐私模式
3. 浏览器存储空间满了

**说明：** localStorage 是浏览器本地存储，换浏览器/清缓存都会丢失。

---

---

## 11. Three.js 渲染管线深入

### 11.1 WebGL 渲染流程全景

Three.js 封装了 WebGL，但理解底层原理对优化至关重要。一帧的渲染流程：

```
JS 逻辑层
  ↓
Three.js 场景更新（矩阵计算、材质更新）
  ↓
WebGL 调用层
  ├─ gl.clear()                // 清屏
  ├─ gl.useProgram()           // 切换着色器程序
  ├─ gl.bindBuffer()           // 绑定顶点缓冲
  ├─ gl.uniformMatrix4fv()     // 设置 Uniform 变量（MVP矩阵等）
  ├─ gl.activeTexture()        // 激活纹理单元
  ├─ gl.bindTexture()          // 绑定纹理
  └─ gl.drawArrays/drawElements()  // 绘制调用（Draw Call）
  ↓
GPU 渲染管线
  ├─ 顶点着色器（Vertex Shader）
  ├─ 图元装配（Primitive Assembly）
  ├─ 光栅化（Rasterization）
  ├─ 片元着色器（Fragment Shader）
  └─ 输出合并（Output Merger）
  ↓
帧缓冲区（Framebuffer）→ 屏幕
```

### 11.2 Draw Call 是什么

Draw Call 是调用 `gl.drawArrays()` 或 `gl.drawElements()` 的次数，是 WebGL 性能的关键指标。

**为什么 Draw Call 多了会卡？**
- 每次 Draw Call 都要切换状态（程序、纹理、Uniform）
- CPU 要给 GPU 发命令，有固定开销
- 一次 Draw Call 画 10 个三角形 和 画 10000 个三角形，CPU 开销差不多

**Three.js 中减少 Draw Call 的方法：**

| 方法 | 原理 | 适用场景 |
|------|------|---------|
| 共享材质 | 相同材质的物体可以合并状态 | 同色家具 |
| 共享几何体 | 同一个 BufferGeometry 复用顶点数据 | 重复物体 |
| InstancedMesh | 一次绘制多个相同物体（GPU Instancing） | 大量重复物体 |
| 合并几何体 | 把多个 Mesh 合并成一个 BufferGeometry | 静态场景 |
| 纹理图集（Atlas） | 多张图拼到一张纹理上，减少纹理切换 | 2D/UI |

**本游戏的做法**：共享材质 + 共享几何体，因为家具种类多但数量不大，这两种优化已经足够。

### 11.3 着色器（Shader）基础

Three.js 的材质背后都是 GLSL 着色器程序。

**顶点着色器（Vertex Shader）**：
- 输入：顶点位置、法线、UV 坐标
- 输出：裁剪空间位置、varying 变量（传递给片元着色器）
- 主要工作：坐标变换（MVP 矩阵乘法）

```glsl
// 简化的顶点着色器
varying vec2 vUv;
varying vec3 vNormal;

void main() {
    vUv = uv;
    vNormal = normalMatrix * normal;
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}
```

**片元着色器（Fragment Shader）**：
- 输入：varying 变量、Uniform 变量、纹理
- 输出：像素颜色
- 主要工作：光照计算、纹理采样

```glsl
// Lambert 材质的片元着色器（简化版）
uniform vec3 diffuse;
uniform vec3 emissive;
varying vec3 vNormal;

void main() {
    vec3 normal = normalize(vNormal);
    float dotProduct = dot(normal, directionalLightDirection);
    float intensity = max(dotProduct, 0.0);
    vec3 color = emissive + diffuse * directionalLightColor * intensity;
    gl_FragColor = vec4(color, 1.0);
}
```

**Lambert 光照模型公式**：
```
最终颜色 = 自发光 + 漫反射颜色 × 光源颜色 × max(N·L, 0)
```
其中 N 是法线方向，L 是光源方向，点积结果表示光线照射角度。

### 11.4 MVP 矩阵变换

3D 物体要显示到 2D 屏幕上，需要三次坐标变换：

```
局部坐标 → 世界坐标 → 视图坐标 → 裁剪坐标 → 屏幕坐标
         ↑         ↑          ↑
    model矩阵   view矩阵   projection矩阵
```

- **Model 矩阵**：物体的位置、旋转、缩放
- **View 矩阵**：相机的位置和朝向（相当于把世界搬到相机前面）
- **Projection 矩阵**：透视投影（近大远小）或正交投影

Three.js 中这些都是自动计算的，但理解原理有助于调试：

```js
// Three.js 内部做的事情
const mvp = new THREE.Matrix4();
mvp.multiplyMatrices(camera.projectionMatrix, camera.matrixWorldInverse);
mvp.multiply(camera.matrixWorld);  // 不对，应该是 object.matrixWorld
// 实际上是：projectionMatrix * viewMatrix * modelMatrix
```

### 11.5 深度测试与深度缓冲

**Z-Buffer（深度缓冲）** 是 WebGL 实现 3D 遮挡关系的核心：

1. 每个像素除了存颜色（颜色缓冲），还存深度值（深度缓冲）
2. 绘制新像素时，比较新深度和旧深度
3. 新像素更近（深度更小）才绘制，否则丢弃

```
颜色缓冲（RGBA）    深度缓冲（Depth）
┌─────────────┐    ┌─────────────┐
│ 像素颜色     │    │ 像素深度值   │
└─────────────┘    └─────────────┘
```

**深度精度问题**：
- 近裁剪面（near）越小，远处的深度精度越差
- 可能出现"Z-fighting"（两个面闪烁交错）
- 所以 near 不要设太小（本游戏 0.1 是合理的）

### 11.6 抗锯齿（MSAA）

```js
renderer = new THREE.WebGLRenderer({ antialias: true });
```

开启抗锯齿后，边缘更平滑。原理是**多重采样抗锯齿（MSAA）**：
- 每个像素采样多个子样本
- 边缘处根据覆盖比例混合颜色
- 比超采样（SSAA）性能好很多

代价：约增加 20-30% 的 GPU 开销。移动端如果卡顿，可以关掉。

---

## 12. 物理引擎实现细节

### 12.1 为什么不用现成物理引擎

Paper Glider 没有使用 Cannon.js、Ammo.js 等物理引擎，原因：

| 方案 | 体积 | 性能 | 开发量 | 适合度 |
|------|------|------|--------|--------|
| 自实现简化物理 | 0（直接写） | 最快 | 少 | ✅ 完美 |
| Cannon.js | ~300KB | 中等 | 中 | ⚠️ 大材小用 |
| Ammo.js (Bullet) | ~1.5MB | 快（WASM） | 多 | ❌ 太重 |

游戏只需要：
- 恒定向前飞行
- 平滑跟随鼠标/触摸
- 简单碰撞检测（球 vs AABB）
- 不需要真实的物理反馈（撞了就 game over）

所以自实现是最优选择。

### 12.2 数值积分：欧拉法

物理更新用的是**显式欧拉法（Explicit Euler）**：

```js
// 每帧更新
position += velocity * deltaTime;
velocity += acceleration * deltaTime;
```

这是最简单的积分方法，优点：
- 实现简单
- 计算快

缺点：
- 精度有限，长时漂移
- 对于弹簧、摆动等可能不稳定

但对于本游戏完全够用，因为：
- 飞机主要是匀速运动
- 位置跟随用 lerp 控制，不依赖精确积分
- 游戏是休闲向，不需要物理精确

### 12.3 Lerp 插值数学

`lerp`（线性插值）是游戏中最常用的函数之一：

```js
function lerp(a, b, t) {
    return a + (b - a) * t;
}
```

参数 `t` 的含义：
- `t = 0` → 返回 `a`
- `t = 1` → 返回 `b`
- `t = 0.5` → 返回中间值

**每帧调用 lerp 的效果**：
```js
// 每帧执行
x = lerp(x, target, 0.1);
```
这不是匀速运动，而是**指数趋近**：
- 初始速度快，越接近目标越慢
- 永远到不了，但视觉上很快就"差不多"了
- 给玩家"平滑跟随"的手感

**为什么用 lerp 而不是直接设置位置？**
- 直接设置 → 瞬移，没有手感
- lerp → 有惯性的感觉，更像真实飞行
- 参数 `t` 控制"灵敏度"：越大跟得越紧，越小越"飘"

### 12.4 碰撞检测：球与 AABB

AABB（Axis-Aligned Bounding Box，轴对齐包围盒）是最简单的碰撞体。

**球与 AABB 碰撞算法**：

```
      ┌─────────────┐
      │             │
      │  AABB       │
      │             │
      └──●──────────┘
         ↑
    最近点 (closest point)
```

步骤：
1. 找到 AABB 上离球心最近的点
2. 计算球心到这个点的距离平方
3. 如果距离平方 < 半径平方 → 碰撞

```js
function sphereAABBCollision(sphereCenter, sphereRadius, box) {
    // box: { minX, maxX, minY, maxY, minZ, maxZ }
    
    // 找最近点
    const closestX = Math.max(box.minX, Math.min(sphereCenter.x, box.maxX));
    const closestY = Math.max(box.minY, Math.min(sphereCenter.y, box.maxY));
    const closestZ = Math.max(box.minZ, Math.min(sphereCenter.z, box.maxZ));
    
    // 计算距离平方（不开平方，性能更好）
    const dx = sphereCenter.x - closestX;
    const dy = sphereCenter.y - closestY;
    const dz = sphereCenter.z - closestZ;
    const distSq = dx * dx + dy * dy + dz * dz;
    
    return distSq < sphereRadius * sphereRadius;
}
```

**为什么用距离平方而不是距离？**
- 开平方（`Math.sqrt`）是比较慢的运算
- 比较大小只需要平方就行
- 性能优化细节：能不开方就不开方

### 12.5 碰撞检测优化：空间分区

如果有 100 个家具，每帧检测 100 次碰撞。对于本游戏来说完全没问题，但如果家具更多，就需要优化。

**空间分区方法**：

| 方法 | 原理 | 适用场景 |
|------|------|---------|
| 网格划分 | 空间分成格子，只检测同一格的物体 | 均匀分布的物体 |
| 四叉树（2D）/八叉树（3D） | 递归划分空间，树结构查询 | 物体分布不均匀 |
| BVH（包围体层次） | 物体组成树状层次结构 | 动态物体 |
| 排序和扫掠 | 按轴排序，只检测重叠区间 | 大量运动物体 |

本游戏用了更简单的优化：**只检测当前房间的家具**。因为飞机只在一个房间里，其他房间的家具不可能撞到。

### 12.6 连续碰撞检测（CCD）

快速移动的物体会有"穿墙"问题：

```
  第1帧         第2帧
   [●] ─────→        [墙]
```
第1帧在墙左边，第2帧在墙右边，中间穿过去了，碰撞检测没抓到。

**本游戏为什么没有这个问题？**
- 飞机速度不算特别快（SPEED_MAX = 30 单位/秒）
- 家具厚度足够（至少 0.5 单位）
- 帧率 60FPS，每帧移动 30/60 = 0.5 单位
- 刚好等于家具厚度，一般不会穿过去

如果要更严谨，可以用**扫掠测试（Sweep Test）**：
- 计算物体从起点到终点的路径
- 检测路径是否与碰撞体相交
- 计算精确的碰撞时间点

但对于休闲游戏来说，通常不需要。

---

## 13. 程序化生成高级技巧

### 13.1 伪随机数与种子

程序化生成的核心是**确定性**：给定相同的种子，生成相同的结果。

**Math.random() 的问题**：
- 不能设置种子
- 每次运行结果不同
- 不利于调试和复现

**带种子的伪随机数生成器（PRNG）**：

```js
// 简单的 Mulberry32 PRNG
function mulberry32(seed) {
    return function() {
        seed |= 0;
        seed = seed + 0x6D2B79F5 | 0;
        let t = Math.imul(seed ^ seed >>> 15, 1 | seed);
        t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
        return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
}

const rand = mulberry32(12345);  // 种子 12345
console.log(rand());  // 每次都是一样的序列
```

好处：
- 可以复现（bug 好排查）
- 可以用种子生成"关卡代码"
- 玩家可以分享种子

本游戏用的是 `Math.random()`，因为不需要复现，每次不一样更好玩。

### 13.2 随机分布策略

**均匀随机**：
```js
// 在房间宽度范围内均匀分布
x = Math.random() * ROOM_W - ROOM_W / 2;
```
问题：可能生成在同一位置，堆在一起。

**泊松盘采样（Poisson Disk Sampling）**：
- 每个随机点之间至少保持最小距离
- 分布均匀且自然，不会太密也不会太疏

```
均匀随机（有聚集）    泊松盘（均匀分散）
●  ●                ●     ●
  ●●        →     ●     ●
●  ●●             ●     ●
```

实现算法：Bridson 算法（2007 年提出）。

本游戏家具数量少，均匀随机足够，不需要泊松盘。

### 13.3 家具摆放策略

好的程序化生成不只是"随机放"，还要考虑：

1. **合理性**：椅子放在桌子旁边，灯放在桌子上
2. **可玩性**：不能把路堵死，飞机要有通道穿过
3. **多样性**：每次玩都不一样

**本游戏的摆放策略**：
- 家具靠墙放（左右墙和后墙）
- 中间留出飞行通道
- 圆环放在通道中，引导玩家
- 家具类型随机组合，每次房间都不一样

### 13.4 难度曲线设计

无尽游戏需要逐渐增加难度，否则玩家很快就腻了。

**难度增加的维度**：
- 飞行速度越来越快
- 家具越来越多/密
- 圆环位置越来越刁钻
- 特殊障碍物出现

**常见的难度曲线**：

| 曲线类型 | 公式 | 特点 |
|---------|------|------|
| 线性 | `难度 = 基础 + 速度 × 时间` | 稳定增长，后期可能太难 |
| 对数 | `难度 = 基础 + log(时间)` | 增长越来越慢，适合休闲 |
| 指数 | `难度 = 基础 × 速度^时间` | 越来越快，硬核游戏 |
| S 型 | 先慢后快再慢 | 有节奏变化 |

本游戏用的是**线性加速**：
```js
// 速度随时间增加
speed = Math.min(SPEED_BASE + playT * 0.1, SPEED_MAX);
```
简单直观，玩家容易感知。

### 13.5 种子化关卡生成进阶

如果想做"关卡代码"功能：

```js
// 玩家输入关卡代码
const seed = hashString("ABC123");
const rand = mulberry32(seed);

// 所有随机数都用这个 rand 生成
function random() { return rand(); }
function randomRange(min, max) { return min + random() * (max - min); }
function randomChoice(arr) { return arr[Math.floor(random() * arr.length)]; }
```

玩家分享关卡代码 = 分享种子，大家玩到完全一样的关卡。

---

## 14. 粒子系统原理

### 14.1 粒子系统是什么

粒子系统是用大量小粒子（通常是四边形/点）来模拟模糊效果：
- 尘埃、烟雾、火花
- 纸屑、羽毛
- 星星、魔法效果

Paper Glider 中有：
- **尘埃粒子**：空气中飘浮的微尘，增加空间感
- **碰撞粒子**：撞到家具时飞溅的纸屑

### 14.2 粒子数据结构

每个粒子有这些属性：

```js
class Particle {
    constructor() {
        this.position = new THREE.Vector3();  // 位置
        this.velocity = new THREE.Vector3();  // 速度
        this.life = 1.0;      // 生命值（1 → 0）
        this.maxLife = 1.0;   // 最大生命
        this.size = 1.0;      // 大小
        this.color = new THREE.Color();  // 颜色
    }
}
```

### 14.3 粒子更新循环

```js
function updateParticles(deltaTime) {
    for (let i = particles.length - 1; i >= 0; i--) {
        const p = particles[i];
        
        // 更新位置
        p.position.addScaledVector(p.velocity, deltaTime);
        
        // 生命衰减
        p.life -= deltaTime / p.maxLife;
        
        // 死亡回收
        if (p.life <= 0) {
            particles.splice(i, 1);
            // 或者放回对象池
        }
    }
}
```

**为什么倒序遍历？**
- 正序遍历 + splice 会跳过元素
- 倒序遍历删除不会影响未处理的索引

### 14.4 粒子渲染方式

| 方式 | 原理 | 性能 | 效果 |
|------|------|------|------|
| Sprite（精灵） | 每个粒子一个平面，始终朝向相机 | 差（每个粒子一个 Draw Call） | 好 |
| Points（点精灵） | `gl.POINTS`，GPU 自动处理朝向 | 好（一次 Draw Call） | 中等 |
| 自定义 Shader | 自己写顶点/片元着色器 | 最好 | 最好 |

本游戏用的是 Three.js 的 `Points` + `PointsMaterial`，性能足够。

### 14.5 粒子对象池

粒子频繁创建和销毁会导致 GC 卡顿。解决方案：**对象池**。

```js
const particlePool = [];

function getParticle() {
    if (particlePool.length > 0) {
        return particlePool.pop();  // 从池里取
    }
    return new Particle();  // 池空了，新建
}

function recycleParticle(p) {
    particlePool.push(p);  // 放回池里
}
```

要点：
- 取出时重置所有属性（不然会有旧数据残留）
- 池大小有上限（避免无限增长）
- 初始预分配一些（减少运行时创建）

### 14.6 粒子性能优化

1. **控制粒子数量**：不是越多越好，够用就行
2. **用简单形状**：点精灵比四边形快
3. **减少透明度叠加**：透明粒子需要排序，开销大
4. **用纹理图集**：多种粒子放一张纹理上
5. **距离剔除**：远处的粒子不渲染或减少数量
6. **降低更新频率**：不是每帧都更新，可以每 2 帧更新一次

---

## 15. WebGL 性能优化深入

### 15.1 性能瓶颈分析

WebGL 性能瓶颈可能在 CPU 或 GPU：

| 瓶颈位置 | 现象 | 原因 | 优化方向 |
|---------|------|------|---------|
| CPU | 帧率低，GPU 利用率低 | Draw Call 太多、JS 逻辑太重 | 减少 Draw Call、优化 JS |
| GPU - 顶点 | 帧率低，像素少也卡 | 顶点太多、顶点着色器复杂 | 减少顶点数、简化顶点着色器 |
| GPU - 片元 | 全屏时更卡 | 片元着色器复杂、填充率不够 | 简化片元着色器、降低分辨率 |
| 内存 | 卡顿（GC 停顿） | 创建销毁太频繁 | 对象池、减少分配 |

**怎么判断瓶颈在哪？**
- Chrome DevTools → Performance 面板看 CPU
- Chrome DevTools → Rendering 面板看 FPS
- 降低分辨率（设 DPR=0.5），帧率大幅提升 → 片元瓶颈
- 减少物体数量，帧率大幅提升 → 顶点/Draw Call 瓶颈

### 15.2 Draw Call 优化进阶

**InstancedMesh（实例化网格）**：

大量相同物体（比如 1000 个方块），用 InstancedMesh 可以一次绘制：

```js
const geometry = new THREE.BoxGeometry(1, 1, 1);
const material = new THREE.MeshLambertMaterial({ color: 0xff0000 });

// 1000 个实例
const mesh = new THREE.InstancedMesh(geometry, material, 1000);

// 设置每个实例的矩阵
const dummy = new THREE.Object3D();
for (let i = 0; i < 1000; i++) {
    dummy.position.set(Math.random() * 100, Math.random() * 100, Math.random() * 100);
    dummy.updateMatrix();
    mesh.setMatrixAt(i, dummy.matrix);
}

scene.add(mesh);
```

**效果**：1000 个物体从 1000 个 Draw Call 降到 1 个。

**适用场景**：大量相同的物体（草、树、建筑）。

本游戏家具种类多、数量少，用不上 InstancedMesh，但知识要知道。

### 15.3 纹理优化

| 优化项 | 做法 | 效果 |
|--------|------|------|
| 尺寸 | 2 的幂（512、1024、2048） | Mipmap 支持好，性能好 |
| 格式 | PNG / JPEG / WebP | WebP 体积最小 |
| Mipmap | 开启（默认） | 远处用小纹理，性能好 |
| 过滤 | Linear / Nearest | Linear 画质好，Nearest 快 |
| 压缩纹理 | ASTC / ETC2 / PVRTC | GPU 内存占用少 |

本游戏的纹理是 Canvas 生成的 256×256，很小，不需要特殊优化。

### 15.4 内存管理

WebGL 资源需要手动释放：

```js
// 释放几何体
geometry.dispose();

// 释放材质
material.dispose();

// 释放纹理
texture.dispose();
```

只 `scene.remove(mesh)` 是不够的，GPU 资源还在。

**本游戏为什么不需要手动释放？**
- 房间用对象池复用，不频繁创建销毁
- 材质和几何体全局共享，只创建一次
- 游戏结束时整个页面可以关掉

但如果做更复杂的游戏（场景切换），一定要注意资源释放。

### 15.5 帧率控制

游戏通常有两种帧率控制方式：

**方式 A：requestAnimationFrame（本游戏用）**
```js
function animate() {
    requestAnimationFrame(animate);
    update(deltaTime);
    render();
}
```
- 跟随显示器刷新率（通常 60Hz）
- 浏览器标签页不可见时自动暂停
- 省电、流畅

**方式 B：固定时间步长**
```js
const FIXED_DT = 1 / 60;
let accumulator = 0;
let lastTime = performance.now();

function animate() {
    const now = performance.now();
    const frameTime = (now - lastTime) / 1000;
    lastTime = now;
    
    accumulator += frameTime;
    while (accumulator >= FIXED_DT) {
        update(FIXED_DT);  // 固定步长更新
        accumulator -= FIXED_DT;
    }
    
    render();
    requestAnimationFrame(animate);
}
```
- 物理更新频率固定，和帧率无关
- 高刷屏上更流畅（插值渲染）
- 实现复杂一点

休闲游戏用方式 A 就够了。

### 15.6 WebGL 2.0 vs WebGL 1.0

Three.js 默认用 WebGL 1.0，也可以用 WebGL 2.0：

```js
renderer = new THREE.WebGLRenderer();
// 如果浏览器支持 WebGL2，Three.js 会自动用吗？
// 答案：r118 之后默认还是 WebGL1，需要手动用 WebGL2Renderer
```

WebGL 2.0 的新特性：
- 更多的纹理单元
- 3D 纹理
- 实例化渲染（ANGLE_instanced_arrays 变成标准）
- 变换反馈（Transform Feedback）
- 更好的着色器（GLSL 300 es）

但兼容性不如 WebGL 1.0（老设备不支持）。

---

## 16. 游戏状态机深入

### 16.1 状态模式（State Pattern）

游戏状态机是状态设计模式的应用：

```
  ┌─────────┐   开始    ┌─────────┐   撞毁   ┌─────────┐
  │  Menu   │ ───────→  │ Playing │ ───────→ │ Crashed │
  └─────────┘           └─────────┘          └────┬────┘
       ↑                                           │
       │              重新开始                      │ 延迟
       └───────────────────────────────────────────┘
                                                      ↓
                                                 ┌─────────┐
                                                 │  Over   │
                                                 └─────────┘
```

每个状态有：
- `enter()`：进入状态时执行一次
- `update(dt)`：每帧更新
- `exit()`：离开状态时执行一次
- 处理输入（鼠标、键盘、触摸）

### 16.2 状态机实现

简单版（本游戏用的）：

```js
let state = 'menu';

function update(dt) {
    switch (state) {
        case 'menu':
            updateMenu(dt);
            break;
        case 'playing':
            updatePlaying(dt);
            break;
        case 'crashed':
            updateCrashed(dt);
            break;
        case 'over':
            updateOver(dt);
            break;
    }
}

function changeState(newState) {
    // 退出旧状态
    // 进入新状态
    state = newState;
}
```

进阶版（面向对象）：

```js
const states = {
    menu: {
        enter() { /* ... */ },
        update(dt) { /* ... */ },
        exit() { /* ... */ },
        onInput(e) { /* ... */ }
    },
    playing: { /* ... */ },
    // ...
};
```

状态少的话，switch 就够了。状态多、逻辑复杂的话，用对象版本更清晰。

### 16.3 游戏循环详解

一个完整的游戏循环：

```
┌─────────────────────────────────────────┐
│              游戏循环                    │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  │
│  │  输入    │→│  更新    │→│  渲染    │  │
│  └─────────┘  └─────────┘  └─────────┘  │
│         ↑                      │        │
│         └──────────────────────┘        │
│              每帧重复                    │
└─────────────────────────────────────────┘
```

**本游戏的每帧更新顺序**：

```js
function tick() {
    const now = performance.now();
    const dt = Math.min((now - lastTime) / 1000, 0.05);  // 最大帧时间，防止跳帧
    lastTime = now;
    
    // 1. 更新游戏逻辑
    if (state === 'playing') {
        updatePlane(dt);       // 更新飞机位置
        updateRooms(dt);       // 更新房间滚动
        updateCollisions(dt);  // 碰撞检测
        updateScore(dt);       // 更新分数
        updateParticles(dt);   // 更新粒子
    }
    
    // 2. 更新 UI（HUD）
    updateHUD();
    
    // 3. 渲染
    renderer.render(scene, camera);
    
    requestAnimationFrame(tick);
}
```

**为什么限制最大帧时间？**
- 如果浏览器卡住了（比如切到后台再切回来）
- `dt` 会很大，飞机可能直接穿墙
- 限制在 0.05 秒（50ms），避免跳帧

### 16.4 时间系统

游戏中有几种时间：

| 时间 | 含义 | 用途 |
|------|------|------|
| `performance.now()` | 浏览器高精度时间（毫秒） | 计算 deltaTime |
| `playT` | 游戏内时间（秒） | 难度曲线、动画计时 |
| `Date.now()` | 现实世界时间 | 每日奖励等 |

**游戏暂停时**：
- `performance.now()` 继续走
- `playT` 暂停累加
- 所以游戏内逻辑都用 `playT`，不要用真实时间

---

## 17. 附录：调试技巧和代码片段

### 17.1 Three.js 调试工具

**Stats.js**：显示 FPS、MS、内存
```js
// 引入 stats.js
const stats = new Stats();
stats.showPanel(0);  // 0: FPS, 1: MS, 2: MB
document.body.appendChild(stats.dom);

function animate() {
    stats.begin();
    // ... 渲染 ...
    stats.end();
    requestAnimationFrame(animate);
}
```

**dat.GUI**：实时调节参数
```js
const gui = new dat.GUI();
gui.add(params, 'speed', 0, 50);
gui.add(params, 'gravity', -20, 20);
```
调参神器，不用改代码刷新页面。

**Three.js Inspector**：Chrome 扩展
- 实时查看场景中的物体
- 检查材质、几何体、纹理
- 可以修改参数看效果

### 17.2 常用调试代码

```js
// 查看场景中有多少物体
console.log(scene.children.length);

// 查看 Draw Call 数量（需要 WebGLRenderer 扩展）
console.log(renderer.info.render.calls);  // Draw Call 数
console.log(renderer.info.render.triangles);  // 三角形数
console.log(renderer.info.memory.geometries);  // 几何体数
console.log(renderer.info.memory.textures);  // 纹理数

// 显示碰撞体（调试用）
function showCollisionBox(obj) {
    const box = new THREE.Box3().setFromObject(obj);
    const helper = new THREE.Box3Helper(box, 0xff0000);
    scene.add(helper);
}

// 显示网格辅助线
const gridHelper = new THREE.GridHelper(50, 50, 0x888888, 0x444444);
scene.add(gridHelper);

// 显示坐标轴
const axesHelper = new THREE.AxesHelper(5);
scene.add(axesHelper);
```

### 17.3 性能测试代码

```js
// 测试渲染一帧需要多久
console.time('render');
renderer.render(scene, camera);
console.timeEnd('render');

// 查看当前帧率（简单版）
let fps = 0;
let frames = 0;
let lastFpsTime = performance.now();

function updateFPS() {
    frames++;
    const now = performance.now();
    if (now - lastFpsTime >= 1000) {
        fps = frames;
        frames = 0;
        lastFpsTime = now;
        console.log('FPS:', fps);
    }
}
```

### 17.4 快速调参代码

```js
// 在控制台直接改参数
// 改速度
SPEED_BASE = 20;  // 基础速度
SPEED_MAX = 50;   // 最大速度

// 改碰撞半径
PLANE_R = 0.5;    // 变大更难，变小更简单

// 改房间大小
ROOM_W = 20;      // 房间变宽

// 无敌模式（上帝模式）
godMode = true;   // 不会撞毁

// 跳转到指定时间
playT = 100;      // 直接到 100 秒的难度
```

### 17.5 常见问题速查

| 问题 | 可能原因 | 检查方法 |
|------|---------|---------|
| 画面黑屏 | WebGL 不支持 / 场景为空 | 检查 console 报错；scene.children 长度 |
| 物体不见了 | 相机位置不对 / 裁剪面 | 把相机位置打出来看 |
| 物体是黑色的 | 没有光 / 法线反了 | 加个 AmbientLight 试试 |
| 闪烁（Z-fighting） | 两个面重合 / 深度精度不够 | 拉开距离；调整 near/far |
| 纹理模糊 | 没有 Mipmap / 过滤方式 | 检查 texture.minFilter |
| 移动端卡顿 | DPR 太高 / 物体太多 | 把 DPR 设为 1 测试 |
| 内存泄漏 | 资源没释放 | 看 renderer.info.memory |

---

## 18. 完整复刻部署指南（从零到上线不出错）

> 本章手把手教你从零搭建一个 Three.js 3D 纸飞机游戏，每步都有验证点，确保不走弯路。

### 18.1 前置准备

**你需要准备的东西**：

| 项目 | 要求 | 说明 |
|------|------|------|
| 文本编辑器 | VS Code / Sublime / 记事本 | 写代码 |
| 浏览器 | Chrome / Firefox / Edge | 测试用，推荐 Chrome |
| 本地 HTTP 服务器 | Python 3 / Node.js | 本地测试 |
| 静态文件托管 | 任意 | GitHub Pages / InfinityFree / Vercel 等 |
| Three.js | CDN 加载即可 | 不需要下载到本地 |

**不需要安装**：
- 不需要 Node.js 构建工具
- 不需要 npm / webpack / vite
- 不需要 Unity / Blender 等重型软件
- 纯单 HTML 文件，零构建

### 18.2 第一步：搭建最小 Three.js 场景

先做一个最简的 Three.js 页面，确认环境没问题。

创建 `index.html`：

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paper Glider - 最小测试</title>
    <style>
        body { margin: 0; overflow: hidden; }
        canvas { display: block; }
    </style>
</head>
<body>
    <!-- Three.js 从 CDN 加载 -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    
    <script>
        // 1. 创建场景
        const scene = new THREE.Scene();
        scene.background = new THREE.Color(0xf6e7cd);  // 暖色调背景
        
        // 2. 创建相机
        const camera = new THREE.PerspectiveCamera(
            62,
            window.innerWidth / window.innerHeight,
            0.1,
            1000
        );
        camera.position.set(0, 5, 10);  // 相机位置
        camera.lookAt(0, 0, 0);         // 看向原点
        
        // 3. 创建渲染器
        const renderer = new THREE.WebGLRenderer({
            antialias: true,
            powerPreference: 'high-performance'
        });
        renderer.setSize(window.innerWidth, window.innerHeight);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        document.body.appendChild(renderer.domElement);
        
        // 4. 加个立方体测试
        const geometry = new THREE.BoxGeometry(1, 1, 1);
        const material = new THREE.MeshLambertMaterial({ color: 0xe0704a });
        const cube = new THREE.Mesh(geometry, material);
        scene.add(cube);
        
        // 5. 加个光
        const light = new THREE.DirectionalLight(0xffffff, 1);
        light.position.set(5, 10, 7);
        scene.add(light);
        
        const ambient = new THREE.AmbientLight(0xffffff, 0.5);
        scene.add(ambient);
        
        // 6. 动画循环
        function animate() {
            requestAnimationFrame(animate);
            
            cube.rotation.x += 0.01;
            cube.rotation.y += 0.02;
            
            renderer.render(scene, camera);
        }
        animate();
        
        // 7. 窗口大小变化时调整
        window.addEventListener('resize', function() {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
        });
    </script>
</body>
</html>
```

**✅ 验证点 1**：
- 浏览器打开 `index.html`（用 HTTP 服务器，不要 file://）
- 看到一个旋转的橙色立方体
- 立方体是有光照的（不是全黑）
- 窗口缩放时，立方体不变形
- 控制台没有报错

> ⚠️ **常见坑**：
> 1. 直接双击打开（file://）可能因为 CORS 策略加载失败
> 2. Three.js CDN 地址写错了会导致 `THREE is not defined`
> 3. 忘了加光，物体会是全黑的

### 18.3 第二步：本地 HTTP 服务器

**为什么需要 HTTP 服务器？**
- Three.js 加载纹理、模型等资源需要 HTTP 协议
- `file://` 协议下，浏览器的安全策略会阻止很多操作
- 本地测试一定要用 HTTP 服务器

**方法 A：Python（推荐，大多数电脑自带）**
```bash
# Python 3
python -m http.server 8080

# Python 2（老版本）
python -m SimpleHTTPServer 8080
```

**方法 B：Node.js**
```bash
# 安装 http-server（只需一次）
npm install -g http-server

# 启动
http-server -p 8080
```

**方法 C：VS Code 插件**
- 安装 "Live Server" 插件
- 右键 HTML 文件 → Open with Live Server

**启动后访问**：`http://localhost:8080/`

**✅ 验证点 2**：
- 终端显示服务器已启动
- 浏览器能访问 `http://localhost:8080/`
- 页面正常显示

### 18.4 第三步：构建纸飞机模型

纸飞机不用 3D 建模软件，用代码构建几何体。

```js
function buildPaperPlane() {
    // 定义顶点（机头、翼尖、机尾等关键点）
    const noseZ = -1.25;
    const tailZ = 1.0;
    const wingSpan = 1.05;
    const wingY = 0.16;
    
    // 顶点数组
    const vertices = [
        // 左翼上表面
        0, 0, noseZ,          // 机头 (0)
        -wingSpan, wingY, tailZ,  // 左翼尖 (1)
        0, wingY * 0.5, tailZ,   // 机身上 (2)
        
        // 右翼上表面
        0, 0, noseZ,          // 机头 (0)
        0, wingY * 0.5, tailZ,   // 机身上 (2)
        wingSpan, wingY, tailZ,   // 右翼尖 (3)
        
        // 左翼下表面
        0, 0, noseZ,
        0, -wingY * 0.3, tailZ,
        -wingSpan, wingY, tailZ,
        
        // 右翼下表面
        0, 0, noseZ,
        wingSpan, wingY, tailZ,
        0, -wingY * 0.3, tailZ,
    ];
    
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(vertices, 3));
    geometry.computeVertexNormals();  // 计算法线，用于光照
    
    // 纸纹理材质
    const material = new THREE.MeshLambertMaterial({
        color: 0xffffff,
        side: THREE.DoubleSide
    });
    
    const mesh = new THREE.Mesh(geometry, material);
    return mesh;
}
```

**✅ 验证点 3**：
- 把立方体换成纸飞机
- 看到一个白色的纸飞机形状
- 光照正常（有明暗）
- 飞机可以旋转移动

### 18.5 第四步：添加房间和家具

先做一个简单的房间盒子：

```js
const ROOM_W = 13, ROOM_H = 9, ROOM_L = 26;

function createRoom(zPosition) {
    const room = new THREE.Group();
    room.position.z = zPosition;
    
    // 地板
    const floorGeo = new THREE.PlaneGeometry(ROOM_W, ROOM_L);
    const floorMat = new THREE.MeshLambertMaterial({ color: 0xe0b47c });
    const floor = new THREE.Mesh(floorGeo, floorMat);
    floor.rotation.x = -Math.PI / 2;
    floor.position.y = 0;
    room.add(floor);
    
    // 天花板
    const ceiling = floor.clone();
    ceiling.position.y = ROOM_H;
    ceiling.rotation.x = Math.PI / 2;
    room.add(ceiling);
    
    // 左右墙
    const wallGeo = new THREE.PlaneGeometry(ROOM_L, ROOM_H);
    const wallMat = new THREE.MeshLambertMaterial({ color: 0xe9dcc4 });
    
    const leftWall = new THREE.Mesh(wallGeo, wallMat);
    leftWall.position.x = -ROOM_W / 2;
    leftWall.position.y = ROOM_H / 2;
    leftWall.rotation.y = Math.PI / 2;
    room.add(leftWall);
    
    const rightWall = leftWall.clone();
    rightWall.position.x = ROOM_W / 2;
    rightWall.rotation.y = -Math.PI / 2;
    room.add(rightWall);
    
    return room;
}
```

**✅ 验证点 4**：
- 场景中出现一个房间盒子
- 地板、天花板、墙都在正确位置
- 纸飞机在房间内部
- 没有穿模（飞机穿墙）

### 18.6 第五步：实现飞行控制

鼠标跟随操控：

```js
let targetX = 0, targetY = 4;

// 鼠标移动
document.addEventListener('mousemove', function(e) {
    // 把鼠标屏幕坐标转换成世界坐标
    // 简化版：根据鼠标在窗口中的位置计算目标位置
    const nx = (e.clientX / window.innerWidth) * 2 - 1;  // -1 到 1
    const ny = -(e.clientY / window.innerHeight) * 2 + 1;
    
    targetX = nx * (ROOM_W / 2 - 1);  // 留边距
    targetY = 1 + (ny + 1) / 2 * (ROOM_H - 2);
});

// 触摸支持
document.addEventListener('touchmove', function(e) {
    e.preventDefault();
    const touch = e.touches[0];
    const nx = (touch.clientX / window.innerWidth) * 2 - 1;
    const ny = -(touch.clientY / window.innerHeight) * 2 + 1;
    targetX = nx * (ROOM_W / 2 - 1);
    targetY = 1 + (ny + 1) / 2 * (ROOM_H - 2);
}, { passive: false });
```

飞行更新（每帧）：

```js
function lerp(a, b, t) {
    return a + (b - a) * t;
}

const SPEED = 13;  // 飞行速度

function updatePlane(dt) {
    // 向前飞
    plane.position.z -= SPEED * dt;
    
    // 左右上下平滑跟随
    plane.position.x = lerp(plane.position.x, targetX, 0.1);
    plane.position.y = lerp(plane.position.y, targetY, 0.1);
    
    // 飞机姿态（倾斜）
    plane.rotation.z = -(targetX - plane.position.x) * 0.3;  // 左右倾斜
    plane.rotation.x = (targetY - plane.position.y) * 0.2;  // 上下倾斜
}
```

**✅ 验证点 5**：
- 鼠标移动时，纸飞机跟随移动
- 移动是平滑的，不是瞬移
- 飞机有倾斜姿态
- 飞机一直向前飞（Z 轴方向）
- 触摸操作也能用

### 18.7 第六步：房间滚动和无尽生成

```js
const rooms = [];
const ROOM_COUNT = 5;  // 同时存在的房间数

// 初始化房间
function initRooms() {
    for (let i = 0; i < ROOM_COUNT; i++) {
        const room = createRoom(-i * ROOM_L);
        rooms.push(room);
        scene.add(room);
    }
}

// 更新房间（每帧）
function updateRooms(dt) {
    const speed = SPEED * dt;
    
    for (let i = 0; i < rooms.length; i++) {
        rooms[i].position.z += speed;  // 房间向后移（飞机相对向前）
    }
    
    // 检查最前面的房间是否滚出视野
    if (rooms[rooms.length - 1].position.z > camera.position.z + 50) {
        // 把最旧的房间移到最前面
        const oldestRoom = rooms.shift();
        oldestRoom.position.z = rooms[rooms.length - 1].position.z - ROOM_L;
        rooms.push(oldestRoom);
        
        // 可以在这里重新生成家具（随机化）
    }
}
```

**✅ 验证点 6**：
- 飞机向前飞，房间不断向后滚动
- 房间无缝衔接（没有缝隙）
- 远处有雾效遮挡
- 不会卡顿（房间数量稳定）

### 18.8 第七步：碰撞检测和游戏结束

```js
const PLANE_R = 0.34;  // 飞机碰撞半径

function checkCollisions() {
    // 墙壁碰撞
    if (plane.position.x < -ROOM_W / 2 + PLANE_R ||
        plane.position.x > ROOM_W / 2 - PLANE_R ||
        plane.position.y < PLANE_R ||
        plane.position.y > ROOM_H - PLANE_R) {
        gameOver();
        return;
    }
    
    // 家具碰撞（遍历当前房间的家具）
    // ... 家具碰撞检测逻辑
}

function gameOver() {
    state = 'over';
    // 显示游戏结束界面
    // 更新最高分
    if (score > bestScore) {
        bestScore = score;
        localStorage.setItem('paperglider_best', String(bestScore));
    }
}
```

**✅ 验证点 7**：
- 撞到墙游戏结束
- 显示游戏结束画面
- 显示最终得分
- 最高分保存到 localStorage
- 刷新页面后最高分还在

### 18.9 第八步：完整功能整合

把所有功能整合起来：

1. 主菜单界面
2. 游戏进行中
3. 碰撞检测
4. 圆环得分
5. 游戏结束
6. 重新开始
7. HUD（得分显示）
8. 粒子效果

**文件结构**：
```
paper-glider/
└── index.html    # 所有代码都在一个文件里
```

**✅ 验证点 8**：
- 主菜单正常显示
- 点击开始进入游戏
- 飞行操控正常
- 碰撞检测正常
- 得分系统正常
- 游戏结束画面正常
- 可以重新开始
- 最高分保存正常

### 18.10 第九步：性能优化检查

部署前做一下性能检查：

**Chrome DevTools 性能面板**：
1. F12 → Performance
2. 点击录制按钮
3. 玩 10 秒游戏
4. 停止录制
5. 看 FPS 曲线

**理想情况**：
- FPS 稳定在 60
- 没有明显的掉帧
- JS 每帧耗时 < 16ms

**如果卡顿**：
1. 降低 DPR 到 1
2. 减少家具数量
3. 减少粒子数量
4. 用 MeshBasicMaterial 代替 Lambert

**✅ 验证点 9**：
- 桌面端 FPS ≥ 60
- 移动端 FPS ≥ 30
- 没有明显的 GC 卡顿
- 内存不会持续增长

### 18.11 第十步：部署上线

**部署方式**：纯静态文件，任何托管都行。

**上传文件清单**：

| 文件 | 必传 | 说明 |
|------|------|------|
| `index.html` | ✅ | 游戏主文件（所有代码都在里面） |

**就一个文件！** 因为：
- Three.js 从 CDN 加载
- 字体从 Google Fonts 加载
- 纹理用 Canvas 程序化生成
- 没有外部图片、音频、模型文件

**部署步骤**：
1. 上传 `index.html` 到服务器
2. 访问线上地址
3. 按测试清单检查

**✅ 验证点 10**：
- 线上地址能正常访问
- 游戏能正常加载和运行
- Three.js CDN 加载正常
- 所有功能和本地一致

### 18.12 完整错误速查表

| 错误现象 | 可能原因 | 精确修复步骤 |
|---------|---------|-------------|
| 页面空白 / 黑屏 | Three.js 没加载 / WebGL 不支持 | 1. F12 看 Console 有没有报错<br>2. 检查 Network 面板 three.js 是否 404<br>3. 访问 https://get.webgl.org/ 测试 WebGL<br>4. 更新显卡驱动或换浏览器 |
| THREE is not defined | Three.js 没加载成功 | 1. 检查 CDN 地址是否正确<br>2. 检查网络连接<br>3. 换个 CDN 源试试 |
| 物体是全黑的 | 没有光源 / 法线反了 | 1. 确认场景中加了光<br>2. 加个 AmbientLight 试试<br>3. 调用 `geometry.computeVertexNormals()`<br>4. 检查材质是不是 `MeshBasicMaterial`（不受光） |
| 物体不见了 | 相机位置不对 / 裁剪面 / 在背面 | 1. 把相机位置打出来确认<br>2. 检查 near/far 裁剪面设置<br>3. 设置 `material.side = THREE.DoubleSide`<br>4. 物体是不是在相机后面 |
| 闪烁（Z-fighting） | 两个面重合 | 1. 把两个面拉开一点距离<br>2. 增大 near 裁剪面<br>3. 用 `PolygonOffset` |
| 移动端卡顿 | DPR 太高 / 物体太多 | 1. 把 DPR 限制在 1.5 或 1<br>2. 减少家具和粒子数量<br>3. 用 MeshBasicMaterial 代替 Lambert |
| 触摸没反应 | touch-action 阻止了 / UI 挡住了 | 1. CSS 加 `touch-action: none`<br>2. 检查是不是 UI 元素挡住了画布<br>3. 确认 `touchmove` 监听正确 |
| 画面模糊 | DPR 太低 / 纹理过滤 | 1. 调高 `setPixelRatio`<br>2. 检查纹理的 minFilter/magFilter<br>3. 确认没有 CSS 缩放 canvas |
| 内存持续增长 | 内存泄漏 | 1. 检查是不是每次都新建几何体/材质<br>2. 确认移除物体时调用了 dispose()<br>3. 用 Chrome Memory 面板拍快照对比 |
| 刷新后分数清零 | localStorage 没存 / 键名错了 | 1. 检查 localStorage 的键名是否正确<br>2. F12 → Application → Local Storage 查看<br>3. 是不是在无痕模式（无痕模式不保存） |
| 帧率不稳定 | GC 卡顿 / 逻辑太重 | 1. 减少每帧的对象创建<br>2. 用对象池复用物体<br>3. Chrome Performance 面板分析 |
| 只有声音没画面 | WebGL 上下文丢失 / GPU 崩溃 | 1. 降低画质<br>2. 监听 `webglcontextlost` 事件<br>3. 移动端常见，减少渲染压力 |

### 18.13 上线前验收清单

- [ ] 桌面端 Chrome 正常运行
- [ ] 桌面端 Firefox 正常运行
- [ ] 桌面端 Edge 正常运行
- [ ] 游戏主菜单正常
- [ ] 飞行操控正常（鼠标 + 键盘 + 触摸）
- [ ] 碰撞检测正常
- [ ] 得分系统正常
- [ ] 最高分保存正常
- [ ] 游戏结束和重新开始正常
- [ ] 帧率稳定（≥60 FPS 桌面 / ≥30 FPS 移动）
- [ ] 控制台没有报错
- [ ] 没有 404 / 500 错误
- [ ] 移动端能正常加载和操作
- [ ] 窗口缩放布局正常
- [ ] 后台标签页切换回来正常

---

**文档版本**：v1.2（完整复刻版）
**最后更新**：2026-10-05
**更新内容**：新增完整复刻部署指南（13 个步骤+验证点）、完整错误速查表（12 种常见错误）、上线前验收清单
