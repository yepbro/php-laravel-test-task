#!/usr/bin/env node
/**
 * lint-tailwind-hex.mjs
 *
 * Запрещает hex/rgb/hsl литералы внутри Tailwind arbitrary-values в `class`/`:class`
 * атрибутах и внутри помощников `cn()`/`clsx()`/`cva()` в .vue/.ts/.tsx файлах.
 *
 * Логика:
 * 1. Обходим resources/js/ ** / *.{vue,ts,tsx}.
 * 2. Находим фрагменты:
 *    - `class="..."`, `:class="..."`, `class='...'`, `:class='...'` в шаблоне;
 *    - строковые литералы, передаваемые в `cn(...)`, `clsx(...)`, `cva(...)`.
 * 3. В найденном фрагменте ищем шаблоны вида `[#...]` и `[(rgb|rgba|hsl|hsla)(...)]`.
 *
 * Allowlist — файлы, которые ещё не переведены на дизайн-токены
 * (см. resources/css/app.css). Новые файлы в allowlist не добавлять.
 *
 * Флаги:
 *   --check   — exit 1 при любой находке вне allowlist (для CI, дефолт).
 *   --report  — напечатать счётчик совпадений по файлам (для ручной инспекции).
 */
import { readFileSync } from 'node:fs';
import { resolve, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { globSync } from 'node:fs';

const ROOT = resolve(fileURLToPath(import.meta.url), '..', '..');
const FS_GLOB_OPTS = { cwd: ROOT, withFileTypes: false };

const args = new Set(process.argv.slice(2));
const MODE = args.has('--report') ? 'report' : 'check';

/*
 * Пусто: все файлы переведены на дизайн-токены из resources/css/app.css.
 * Новые файлы в allowlist не добавлять.
 */
const ALLOWLIST = new Set([]);

const PATTERNS = [
    /\[#[0-9a-fA-F]{3,8}(?:\/\d+(?:\.\d+)?)?\]/,
    /\[(?:rgb|rgba|hsl|hsla)\([^\]]+\)\]/,
];

/*
 * Широкий пасс: любой hex/rgb/hsl в Tailwind arbitrary-value, где бы он ни
 * находился в файле (.vue <script>, .ts константы, className-пропы и т.д.).
 * Ловит в т.ч. кейсы, которые не попадают в class/:class/cn/clsx/cva
 * контексты (classes.push('bg-[#…]'), computed-строки, className="…").
 */
const RAW_PATTERNS = [
    // `-[#hex]` c префиксом Tailwind-утилиты (bg-, text-, border-, fill- и т.д.)
    /[a-zA-Z][a-zA-Z-]*-\[#[0-9a-fA-F]{3,8}(?:\/\d+(?:\.\d+)?)?\]/,
    // `-[rgb(...)]` / `-[rgba(...)]` / `-[hsl(...)]` / `-[hsla(...)]`
    /[a-zA-Z][a-zA-Z-]*-\[(?:rgb|rgba|hsl|hsla)\([^\]]+\)\]/,
];

/**
 * Достаёт все «class-контексты» из исходника — значения class/:class атрибутов
 * и аргументы cn()/clsx()/cva(). Возвращает массив {start, end, text}.
 */
function extractClassContexts(source) {
    const contexts = [];

    /*
     * Атрибуты class / :class / className. Левый якорь `(^|[\s(])` отсекает
     * ложные совпадения на data-*, myclass, css-class и т.п. (не `\b`, чтобы
     * `:class` тоже ловилось — `:` у `\b` со стороны слова даёт границу не там).
     */
    const attrRe =
        /(?:^|[\s(])(?::?class|className)\s*=\s*(?:"([^"]*)"|'([^']*)')/g;
    let m;
    while ((m = attrRe.exec(source)) !== null) {
        const value = m[1] ?? m[2] ?? '';
        const idx = m.index + m[0].indexOf(value);
        contexts.push({ start: idx, end: idx + value.length, text: value });
    }

    // cn(...), clsx(...), cva(...) — грубо, но достаточно для поиска hex в строках.
    const helperRe = /\b(?:cn|clsx|cva)\s*\(([\s\S]*?)\)/g;
    while ((m = helperRe.exec(source)) !== null) {
        contexts.push({
            start: m.index,
            end: m.index + m[0].length,
            text: m[1],
        });
    }

    return contexts;
}

function findHexInFile(absPath) {
    const src = readFileSync(absPath, 'utf8');
    const hits = new Set();
    for (const ctx of extractClassContexts(src)) {
        for (const re of PATTERNS) {
            const localRe = new RegExp(re.source, 'g');
            let mm;
            while ((mm = localRe.exec(ctx.text)) !== null) {
                hits.add(mm[0]);
            }
        }
    }
    for (const re of RAW_PATTERNS) {
        const localRe = new RegExp(re.source, 'g');
        let mm;
        while ((mm = localRe.exec(src)) !== null) {
            hits.add(mm[0]);
        }
    }
    return [...hits];
}

function main() {
    const patterns = [
        'resources/js/**/*.vue',
        'resources/js/**/*.ts',
        'resources/js/**/*.tsx',
    ];
    const files = new Set();
    for (const p of patterns) {
        for (const f of globSync(p, FS_GLOB_OPTS)) {
            files.add(relative(ROOT, resolve(ROOT, f)));
        }
    }

    const dirtyFiles = new Map();

    for (const rel of [...files].sort((a, b) => a.localeCompare(b))) {
        const hits = findHexInFile(resolve(ROOT, rel));
        if (hits.length > 0) {
            dirtyFiles.set(rel, hits);
        }
    }

    if (MODE === 'report') {
        console.log(
            `Файлов с hex в Tailwind arbitrary-values: ${dirtyFiles.size}`,
        );
        for (const [rel, hits] of dirtyFiles) {
            const mark = ALLOWLIST.has(rel) ? ' (allowlist)' : '';
            console.log(`  ${rel}: ${hits.length}${mark}`);
        }
        return;
    }

    let failures = 0;
    for (const [rel, hits] of dirtyFiles) {
        if (ALLOWLIST.has(rel)) {
            continue;
        }
        failures++;
        console.error(`✗ ${rel}`);
        for (const h of [...new Set(hits)].slice(0, 5)) {
            console.error(`    ${h}`);
        }
        if (hits.length > 5) {
            console.error(`    … и ещё ${hits.length - 5} совпадений`);
        }
    }

    if (failures > 0) {
        console.error(
            `\nНе зелёный: найдены hex-литералы во Vue/TS. Замени на токены из resources/css/app.css.`,
        );
        process.exit(1);
    }
    console.log(
        `OK: hex-литералов во Vue/TS не найдено (в allowlist: ${ALLOWLIST.size}).`,
    );
}

main();
