<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  Unity WebGL 分块文件合并脚本（PHP 版）
 * ═══════════════════════════════════════════════════════════════
 *
 * 【为什么需要这个脚本】
 *  InfinityFree 等虚拟主机有单文件 10MB 上传限制，
 *  而 Unity WebGL 打包出的 .unityweb 文件通常超过 10MB。
 *  解决方案：将大文件拆分为多个 .partN 小文件上传，
 *  此脚本收到 .unityweb 请求时，动态合并分块后输出。
 *  对 Unity 引擎完全透明，就像在访问完整文件一样。
 *
 * 【调用方式】
 *  通常由 .htaccess 的 mod_rewrite 自动转发：
 *    RewriteRule ^(.+\.unityweb)$ unityweb.php?f=$1 [L,QSA]
 *
 *  也可以直接访问：
 *    unityweb.php?f=ppw.data.unityweb
 *
 * 【支持的功能】
 *  ✅ 白名单机制（防止路径遍历攻击）
 *  ✅ 正确的 MIME 类型（WASM 流式编译必需）
 *  ✅ HTTP Range 断点续传（Unity 加载 WASM 会用）
 *  ✅ 30 天缓存头（减少重复下载）
 *  ✅ 小文件直通（framework 文件不分块，直接 readfile）
 *
 * 【文件命名约定】
 *  ppw.data.unityweb.part0     第1块
 *  ppw.data.unityweb.part1     第2块
 *  ...
 *
 * 【本地对应实现】
 *  本地开发用 run_local.py（Python），行为与此脚本完全一致，
 *  确保本地测试结果 = 线上运行结果。
 * ═══════════════════════════════════════════════════════════════
 */

// ──────────────────────────────────────────────
//  配置区
// ──────────────────────────────────────────────

/**
 * 允许访问的文件白名单
 * 键 = 文件名，值 = 分块数量（0 表示不分块，直接输出原文件）
 * 只有在此列表中的文件才会被处理，防止 ../ 路径遍历攻击
 */
$ALLOWED_FILES = [
    'ppw.data.unityweb'           => 2,   // 游戏数据文件（约12MB，拆成2块）
    'ppw.wasm.code.unityweb'      => 3,   // WASM 代码文件（约20MB，拆成3块）
    'ppw.wasm.framework.unityweb' => 0,   // JS 框架文件（约512KB，太小不用分块）
];

// ──────────────────────────────────────────────
//  工具函数
// ──────────────────────────────────────────────

/**
 * 根据 Unity WebGL 文件名返回正确的 MIME 类型
 *
 * 为什么重要：
 *   WebAssembly 流式编译（WebAssembly.instantiateStreaming）
 *   要求响应的 Content-Type 必须是 application/wasm。
 *   如果 MIME 不对，浏览器会回退到 ArrayBuffer 模式：
 *   - 速度更慢（需要先完整下载再编译）
 *   - 内存占用更高（整个 WASM 文件在内存中复制一份）
 *   - 大文件可能因内存不足导致卡死
 *
 * @param string $filename 文件名
 * @return string MIME 类型
 */
function get_mime_type($filename) {
    if (strpos($filename, 'wasm.code') !== false) {
        return 'application/wasm';          // WASM 代码 → 流式编译
    } elseif (strpos($filename, 'wasm.framework') !== false) {
        return 'application/javascript';     // JS 框架 → 普通脚本
    } else {
        return 'application/octet-stream';   // 数据文件 → 二进制流
    }
}

/**
 * 发送 404 响应并退出
 * @param string $msg 错误信息
 */
function send_404($msg = 'File not found') {
    header('HTTP/1.0 404 Not Found');
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

// ──────────────────────────────────────────────
//  主逻辑
// ──────────────────────────────────────────────

// 1. 获取并校验请求的文件名
$f = isset($_GET['f']) ? $_GET['f'] : '';

// 白名单校验：不在列表中的直接 404
if (!isset($ALLOWED_FILES[$f])) {
    send_404('File not found');
}

$part_count = $ALLOWED_FILES[$f];

// 2. 不分块的小文件：直接输出（性能最优）
if ($part_count === 0) {
    $filepath = __DIR__ . '/' . $f;
    if (!file_exists($filepath)) {
        send_404('File not found');
    }

    $filesize = filesize($filepath);
    $mime = get_mime_type($f);

    header('HTTP/1.1 200 OK');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $filesize);
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=2592000'); // 30 天

    // readfile 是 PHP 输出文件最高效的方式（直接走内核缓冲区）
    readfile($filepath);
    exit;
}

// 3. 分块文件：校验所有分块是否存在，并计算总大小
$total_size = 0;
$part_files = [];  // 存储每个分块的路径和大小

for ($i = 0; $i < $part_count; $i++) {
    $part_path = __DIR__ . '/' . $f . '.part' . $i;
    if (!file_exists($part_path)) {
        send_404('Part ' . $i . ' not found');
    }
    $size = filesize($part_path);
    $total_size += $size;
    $part_files[] = [
        'path' => $part_path,
        'size' => $size,
    ];
}

// 4. 处理 HTTP Range 请求（断点续传）
//    Unity 加载 WASM 时可能先用 Range 请求探测文件大小
$offset = 0;        // 起始偏移（字节）
$length = $total_size; // 读取长度（字节）

if (isset($_SERVER['HTTP_RANGE'])) {
    // 解析 Range 头，格式：bytes=START-END
    // 支持两种形式：bytes=100-200  或  bytes=100-
    if (preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $m)) {
        $offset = intval($m[1]);
        $end = isset($m[2]) ? intval($m[2]) : $total_size - 1;

        // 边界检查
        if ($end >= $total_size) {
            $end = $total_size - 1;
        }
        if ($offset > $end) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $total_size);
            exit;
        }

        $length = $end - $offset + 1;

        // 返回 206 Partial Content
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $offset . '-' . $end . '/' . $total_size);
    } else {
        // Range 格式不认识，返回完整文件
        header('HTTP/1.1 200 OK');
    }
} else {
    // 没有 Range 头，返回完整文件
    header('HTTP/1.1 200 OK');
}

// 5. 发送响应头
$mime = get_mime_type($f);
header('Content-Type: ' . $mime);
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=2592000'); // 30 天

// 6. 合并输出分块数据
//
// 算法思路：
//   想象把所有分块文件首尾相接，组成一个"虚拟大文件"。
//   我们需要从这个虚拟文件的 offset 位置开始，输出 length 字节。
//
//   遍历每个分块：
//   - 如果整个块都在 offset 之前 → 跳过
//   - 如果块和请求范围有交集  → 计算交集部分，读取并输出
//   - 如果已经输出够了        → 提前退出
//
//   时间复杂度 O(n)，n = 分块数量，通常只有 2-3 块，非常快。

$remaining = $length;   // 还剩多少字节需要输出
$cur_offset = 0;        // 当前分块在虚拟文件中的起始位置

foreach ($part_files as $part) {
    $part_size = $part['size'];
    $part_path = $part['path'];

    // 情况1：当前分块完全在请求起点之前 → 跳过
    if ($cur_offset + $part_size <= $offset) {
        $cur_offset += $part_size;
        continue;
    }

    // 情况2：已经输出够了 → 退出
    if ($remaining <= 0) {
        break;
    }

    // 情况3：分块与请求范围有交集 → 读取并输出
    // 计算在这个分块中的起始位置
    $part_start = max(0, $offset - $cur_offset);
    // 这个分块从起始位置往后有多少可用字节
    $part_available = $part_size - $part_start;
    // 实际读取量 = 可用量 和 剩余需求量 取较小值
    $read_len = min($part_available, $remaining);

    if ($read_len > 0) {
        $fp = fopen($part_path, 'rb');
        fseek($fp, $part_start);        // 定位到分块内的起始位置
        echo fread($fp, $read_len);     // 读取并直接输出到响应流
        fclose($fp);
        $remaining -= $read_len;
    }

    $cur_offset += $part_size;
}
