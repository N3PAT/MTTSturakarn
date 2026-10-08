<?php
/**
 * rich_content.php — แสดงรูป/ลิงก์ในข้อความประกาศอย่างปลอดภัย
 *
 *   rich_html($text)  → HTML ที่กรองแล้ว (ใส่ใน <template> / <div> ได้เลย)
 *   rich_plain($text) → ข้อความล้วน ใช้เป็นพรีวิวในการ์ด
 *
 * รองรับ: แท็ก HTML ที่อนุญาต, [ข้อความ](url), ![alt](url), URL เปล่า ๆ
 * ต้องเปิดส่วนขยาย php-xml (DOMDocument)
 */

/* URL ที่ปลอดภัย: http(s) / mailto / tel / path ภายในเว็บ — นอกนั้น (javascript:, data: ฯลฯ) ปฏิเสธ */
function rich_safe_url(string $u, bool $isImg = false): ?string {
    $u   = trim($u);
    $chk = preg_replace('/[\x00-\x20\x7f]+/', '', $u);      // กัน "java\tscript:"
    if ($chk === null || $chk === '') return null;
    if (preg_match('~^https?://~i', $chk)) return $u;
    if (!$isImg && preg_match('~^(?:mailto|tel):~i', $chk)) return $u;
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $chk)) return null;
    if (preg_match('~^(?://|/\\\\|\\\\)~', $chk)) return null;
    return $u;
}

/* style: เก็บเฉพาะคุณสมบัติที่ปลอดภัย (ขนาด สี ขอบ ระยะห่าง) — ไม่เอา position / url() / font-family */
function rich_clean_style(string $css): string {
    static $ok = null;
    if ($ok === null) {
        $ok = array_flip([
            'display','width','max-width','min-width','height','min-height','max-height','aspect-ratio',
            'margin','margin-top','margin-bottom','margin-left','margin-right',
            'padding','padding-top','padding-bottom','padding-left','padding-right',
            'gap','flex','flex-direction','flex-wrap','flex-shrink','align-items','justify-content',
            'color','background','background-color','border','border-top','border-bottom','border-radius','box-shadow',
            'font-size','font-weight','font-style','line-height','letter-spacing',
            'text-align','text-transform','text-decoration','overflow','object-fit','white-space','word-break',
        ]);
    }
    $out = [];
    foreach (explode(';', $css) as $decl) {
        $p = strpos($decl, ':');
        if ($p === false) continue;
        $prop = strtolower(trim(substr($decl, 0, $p)));
        $val  = trim(substr($decl, $p + 1));
        if ($val === '' || !isset($ok[$prop])) continue;
        if (preg_match('/url\s*\(|image|src\s*\(|expression|javascript|@|[\\\\<>{}]|\/\*/i', $val)) continue;
        $out[] = $prop . ':' . $val;
    }
    return implode(';', $out);
}

function rich_make_img(DOMDocument $doc, string $src, string $alt = ''): DOMElement {
    $i = $doc->createElement('img');
    $i->setAttribute('src', $src);
    $i->setAttribute('alt', $alt);
    $i->setAttribute('loading', 'lazy');
    $i->setAttribute('referrerpolicy', 'no-referrer');
    return $i;
}

function rich_make_a(DOMDocument $doc, string $href, string $label): DOMElement {
    $a = $doc->createElement('a');
    $a->setAttribute('href', $href);
    $a->setAttribute('target', '_blank');
    $a->setAttribute('rel', 'noopener noreferrer');
    $a->appendChild($doc->createTextNode($label));
    return $a;
}

function rich_unwrap(DOMNode $parent, DOMNode $el): void {
    while ($el->firstChild) { $parent->insertBefore($el->firstChild, $el); }
    $parent->removeChild($el);
}

function rich_in_anchor(DOMNode $n): bool {
    for ($p = $n->parentNode; $p; $p = $p->parentNode) {
        if ($p instanceof DOMElement && strtolower($p->nodeName) === 'a') return true;
    }
    return false;
}

/* ข้อความล้วน: แปลง Markdown / URL เป็นลิงก์-รูป และขึ้นบรรทัดใหม่เป็น <br> */
function rich_text(DOMDocument $doc, DOMText $t): void {
    $s = (string)$t->nodeValue;
    if (trim($s) === '') {                       // ช่องว่าง/ขึ้นบรรทัดระหว่างแท็ก → เว้นวรรคเดียว
        $t->nodeValue = ($s === '') ? '' : ' ';
        return;
    }
    $frag = $doc->createDocumentFragment();
    $addText = function (string $txt) use ($doc, $frag): void {
        foreach (preg_split('/\r\n|\r|\n/', $txt) as $i => $line) {
            if ($i > 0) $frag->appendChild($doc->createElement('br'));
            if ($line !== '') $frag->appendChild($doc->createTextNode($line));
        }
    };

    $m = [];
    if (!rich_in_anchor($t)) {                   // อยู่ใน <a> แล้วไม่ต้องทำลิงก์ซ้อน
        preg_match_all(
            '~!\[([^\]\r\n]*)\]\(\s*(https?://[^\s)]+)\s*\)|\[([^\]\r\n]+)\]\(\s*(https?://[^\s)]+)\s*\)|https?://[^\s<>"\')\]]+~iu',
            $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
    }

    $pos = 0;
    foreach ((array)$m as $mm) {
        $full = $mm[0][0];
        $off  = $mm[0][1];
        $addText(substr($s, $pos, $off - $pos));
        $pos = $off + strlen($full);

        $imgUrl  = $mm[2][0] ?? '';
        $linkUrl = $mm[4][0] ?? '';
        if ($imgUrl !== '') {                                    // ![alt](url)
            $src = rich_safe_url($imgUrl, true);
            if ($src !== null) $frag->appendChild(rich_make_img($doc, $src, $mm[1][0] ?? ''));
            else $addText($full);
        } elseif ($linkUrl !== '') {                             // [ข้อความ](url)
            $href = rich_safe_url($linkUrl);
            if ($href !== null) $frag->appendChild(rich_make_a($doc, $href, $mm[3][0]));
            else $addText($full);
        } else {                                                 // URL เปล่า
            $url  = rtrim($full, '.,;:!?');
            $tail = substr($full, strlen($url));
            if (preg_match('~\.(?:jpe?g|png|gif|webp)(?:\?\S*)?$~i', $url)) {
                $frag->appendChild(rich_make_img($doc, $url));
            } else {
                $frag->appendChild(rich_make_a($doc, $url, $url));
            }
            if ($tail !== '') $addText($tail);
        }
    }
    $addText(substr($s, $pos));
    $t->parentNode->replaceChild($frag, $t);
}

function rich_walk(DOMDocument $doc, DOMNode $node): void {
    static $allow  = ['a','img','br','p','div','span','b','strong','i','em','u','small','ul','ol','li','h3','h4','blockquote'];
    static $remove = ['script','style','iframe','object','embed','form','input','button','textarea','select','svg','math','link','meta','base','template','noscript'];

    foreach (iterator_to_array($node->childNodes) as $ch) {
        if ($ch instanceof DOMText) { rich_text($doc, $ch); continue; }
        if (!($ch instanceof DOMElement)) { $node->removeChild($ch); continue; }   // คอมเมนต์ ฯลฯ

        $name = strtolower($ch->nodeName);
        if (in_array($name, $remove, true)) { $node->removeChild($ch); continue; }

        rich_walk($doc, $ch);                                    // ทำลูกก่อน

        if (!in_array($name, $allow, true)) { rich_unwrap($node, $ch); continue; }

        $keep = [];
        if ($name === 'a') {
            $href = rich_safe_url((string)$ch->getAttribute('href'));
            if ($href === null) { rich_unwrap($node, $ch); continue; }
            $keep = ['href' => $href, 'target' => '_blank', 'rel' => 'noopener noreferrer'];
        } elseif ($name === 'img') {
            $src = rich_safe_url((string)$ch->getAttribute('src'), true);
            if ($src === null) { $node->removeChild($ch); continue; }
            $keep = ['src' => $src, 'alt' => (string)$ch->getAttribute('alt'), 'loading' => 'lazy', 'referrerpolicy' => 'no-referrer'];
        }
        $style = rich_clean_style((string)$ch->getAttribute('style'));
        if ($style !== '') $keep['style'] = $style;

        while ($ch->attributes->length) { $ch->removeAttributeNode($ch->attributes->item(0)); }
        foreach ($keep as $k => $v) { $ch->setAttribute($k, $v); }
    }
}

/* ข้อความดิบ → HTML ที่กรองแล้ว */
function rich_html(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $fallback = nl2br(htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'));
    if (!class_exists('DOMDocument')) return $fallback;

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument('1.0', 'UTF-8');
    $ok   = $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $raw . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $ok ? $doc->getElementsByTagName('div')->item(0) : null;
    if (!$root) return $fallback;

    rich_walk($doc, $root);
    $html = '';
    foreach ($root->childNodes as $c) { $html .= $doc->saveHTML($c); }
    return trim($html);
}

/* ข้อความล้วนสำหรับพรีวิวในการ์ด (ตัดแท็ก/ลิงก์ Markdown เหลือแต่ข้อความ) */
function rich_plain(string $raw): string {
    $s = preg_replace('~<(?:script|style)\b.*?</(?:script|style)>~is', '', $raw) ?? $raw;
    $s = preg_replace('~<br\s*/?>|</(?:p|div|li|h[1-6])>~i', "\n", $s) ?? $s;
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('~!\[[^\]]*\]\([^)]*\)~', '', $s) ?? $s;
    $s = preg_replace('~\[([^\]]+)\]\([^)]*\)~', '$1', $s) ?? $s;
    $s = preg_replace('~[ \t]+~', ' ', $s) ?? $s;
    $s = preg_replace('~\s*\n\s*~', "\n", $s) ?? $s;
    return trim($s);
}
