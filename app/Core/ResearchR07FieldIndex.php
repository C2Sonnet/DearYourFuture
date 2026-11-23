<?php
declare(strict_types=1);
namespace KMM\Core;

/**
 * Read-only detailed source fields for d1.0.4 R07 Batch50.
 * Source values, unverified statuses and separate course labels are preserved.
 * This is a snapshot index, never a DB update or evidence verification.
 */
final class ResearchR07FieldIndex {
    private const INDEX_SHA256 = '1b1ede4757e328b1fd0a11ca8f5d6d6f031ef031b3ab819c98613d936daaadc3';
    private static ?array $index = null;

    private static function all(): array {
        if (self::$index !== null) return self::$index;
        $path = ResearchR07::root().'/RESEARCH_FIELD_INDEX_d1.0.4_R07_BATCH50.json';
        if (!is_file($path) || !hash_equals(self::INDEX_SHA256, (string)hash_file('sha256', $path))) {
            throw new \RuntimeException('R07 field-index integrity mismatch');
        }
        $decoded = json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['source_sha256']??'') !== ResearchR07::FILE_SHA256
            || count($decoded['jobs']??[]) !== 143 || count($decoded['companies']??[]) !== 112) {
            throw new \RuntimeException('R07 field-index record mismatch');
        }
        return self::$index = $decoded;
    }

    /** Complete internal index rows; consumer filters to applicant-relevant fields. */
    public static function rows(string $kind,string $sourceId):array {
        if (!in_array($kind,['companies','jobs'],true))return [];
        $entry=self::all()[$kind][$sourceId]??null;
        if (!is_array($entry))return [];
        $rows=[];foreach(($entry['groups']??[]) as $group=>$items){
            if(!is_array($items))continue;
            foreach($items as $item)if(is_array($item))$rows[]=$item;
        }
        return $rows;
    }
    public static function render(string $kind, string $sourceId): string {
        if (!in_array($kind, ['companies', 'jobs'], true)) return '';
        if (($kind === 'jobs' && !ResearchR07::isJobId($sourceId))
            || ($kind === 'companies' && !ResearchR07::isCompanyId($sourceId))) return '';
        $entry = self::all()[$kind][$sourceId] ?? null;
        if (!is_array($entry)) return '';
        $groups = is_array($entry['groups']??null) ? $entry['groups'] : [];
        $links = is_array($entry['links']??null) ? $entry['links'] : [];
        $label = $kind === 'jobs' ? '求人' : '企業';
        $count = 0;
        foreach ($groups as $rows) $count += is_array($rows) ? count($rows) : 0;
        $html = '<section class="section r07-field-index" aria-label="'.Security::h($label).'の原典追加項目">'
            .'<h2>原典の追加項目</h2><p class="r07-field-intro">R07 Batch50の現行記録に収録された項目を、情報源・コースごとに独立表示します（'.(int)$count.'項目）。'
            .'「false（原典値）」は確認フラグであり、応募不可や募集終了の確定ではありません。別募集・別年度の条件をこの求人に自動適用しません。</p>';
        $groupNumber = 0;
        foreach ($groups as $group => $rows) {
            if (!is_string($group) || !is_array($rows) || $rows === []) continue;
            // Salary and workplace are expanded to make important conditions immediately visible.
            $open = in_array($group, ['給与・手当・休日', '勤務先・交通・勤務時間', '応募条件・雇用形態'], true) ? ' open' : '';
            $html .= '<details class="r07-field-group"'.$open.'><summary>'.Security::h($group).'（'.count($rows).'項目）</summary>';
            $html .= '<div class="r07-table-wrap"><table class="r07-fact-table"><tbody>';
            foreach ($rows as $item) {
                if (!is_array($item)) continue;
                $fieldLabel = $item['label']??null;
                $value = $item['value']??null;
                $source = $item['source']??null;
                $path = $item['path']??null;
                if (!is_string($fieldLabel) || !is_string($value) || trim($value) === '') continue;
                $html .= '<tr><th scope="row">'.Security::h($fieldLabel).'</th><td>'
                    .'<div class="r07-field-value">'.Security::h($value).'</div>';
                if (is_string($source) && $source !== '') {
                    $html .= '<small class="r07-field-source">出典区分：'.Security::h($source).'</small>';
                }
                if (is_string($path) && $path !== '') {
                    $html .= '<small class="r07-field-path">原典項目：'.Security::h($path).'</small>';
                }
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table></div></details>';
            $groupNumber++;
        }
        if ($links !== []) {
            $html .= '<details class="r07-field-group"><summary>追加原典リンク（'.count($links).'件）</summary><div class="r07-source-links">';
            foreach ($links as $item) {
                if (!is_array($item) || !ResearchExternalLinks::safeUrl($item['url']??null)) continue;
                $html .= '<a class="btn ghost external-link" href="'.Security::h($item['url']).'" target="_blank" rel="noopener noreferrer nofollow external">'
                    .Security::h((string)($item['source']??'原典')).' ↗</a>';
            }
            $html .= '</div></details>';
        }
        $html .= '<p class="note">この索引は資料中の収録値を表示するもので、採用枠の現在の受付・応募資格・所在地等の再確認や保証を意味しません。</p></section>';
        return $html;
    }
}
