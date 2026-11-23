<?php
declare(strict_types=1);
namespace KMM\Core;

/** Checklist from published fields only; no invented age, nationality or visa eligibility. */
final class JobEligibility {
    public const QUESTIONS = [
        'graduation'=>'この求人は自分の卒業年度・専攻に対応していますか？',
        'experience'=>'専門学校卒業予定者／既卒者／就業歴の条件を直接確認しましたか？',
        'technical_role'=>'実際の職務は製菓・製パン・研究開発・品質管理など希望する専門技術職ですか？',
        'international'=>'留学生や外国籍の応募条件について募集元に確認しましたか？',
        'residency'=>'在留資格の種類・就労内容・変更手続きの可否を適切な窓口で確認しましたか？',
        'availability'=>'応募締切だけでなく残りの採用枠と現時点の受付継続を確認しましたか？',
        'worksite'=>'本社ではなく、実際に配属される勤務地・異動条件を確認しましたか？',
        'benefits'=>'待遇、勤務時間、休日、残業、給与内訳を公式資料で確認しましたか？',
    ];
    public static function render(array $job,?array $source=null,array $checked=[],bool $canSave=false):string {
        $year=(int)($job['graduate_year']??0);
        $observed=(string)($job['observed_on']??'');
        $deadline=(string)($job['deadline_on']??'');
        $state=(string)($job['recruitment_state']??'unverified');
        $notice=match($state){
            'confirmed_open'=>'情報を確認した日時点で受付確認済み（残枠・現在の受付は要再確認）',
            'listed'=>'募集掲載の記録あり・残枠と受付継続は未確認',
            'closed'=>'受付終了の情報あり',
            default=>'募集の受付状況は未確認',
        };
        $html='<section class="section"><h2>応募資格・職種のセルフチェック</h2><p class="note">以下は応募を判断するための確認項目です。企業が個別の応募資格や在留資格を保証したものではありません。チェックは企業の採用判断ではありません。ログインすると、本人専用のチェック状態を保存できます。</p>';
        $html.='<p>卒業年度の掲載情報：<strong>'.($year>0?$year.'年卒':'未確認').'</strong> ／ 募集：'.Security::h($notice).' ／ 情報確認日：'.Security::h($observed?:'未記録').' ／ 締切：'.Security::h($deadline?:'未確認').'</p>';
        if($source!==null&&ResearchExternalLinks::safeUrl($source['source_url']??null))$html.='<p>求人の参照資料：<a class="text-link" href="'.Security::h($source['source_url']).'" target="_blank" rel="noopener noreferrer nofollow">'.Security::h((string)($source['title']??'参照資料を開く')).'</a></p>';
        $jobId=is_string($job['id']??null)?$job['id']:null;
        if($canSave && $jobId===null)throw new \InvalidArgumentException('A saved checklist requires a real job ID');
        if($canSave)$html.='<form action="/jobs/'.Security::h($jobId).'/checklist" method="post">'.Session::csrfField();
        $html.='<fieldset class="eligibility-checklist"><legend>自分で確認した項目にチェック</legend>';
        foreach(self::QUESTIONS as $key=>$question){
            $selected=in_array($key,$checked,true)?' checked':'';
            $html.='<label><input type="checkbox" name="checked[]" value="'.Security::h($key).'"'.$selected.'> '.Security::h($question).'</label>';
        }
        $html.='</fieldset>';
        if($canSave)$html.='<button class="btn primary" type="submit">自分用チェックを保存</button></form>';
        elseif($jobId!==null)$html.='<p><a href="/account/login?next='.rawurlencode('/jobs/'.$jobId).'">ログインしてチェックを保存</a></p>';
        $html.='<p class="note">未確認は「応募不可」を意味しません。自分用の確認状態は企業による応募資格の保証ではありません。</p></section>';
        return $html;
    }
}
