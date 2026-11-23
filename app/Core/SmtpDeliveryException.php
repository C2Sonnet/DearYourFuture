<?php
declare(strict_types=1);
namespace KMM\Core;

/** Never contains credentials, SMTP response text or message content. */
final class SmtpDeliveryException extends \RuntimeException {
    public function __construct(public readonly string $phase,public readonly ?int $smtpCode=null,public readonly ?int $socketErrno=null) {
        parent::__construct('SMTP failure: '.$phase.($smtpCode!==null?' (SMTP '.$smtpCode.')':''));
    }
    public function publicSummary():string {return '処理段階: '.$this->phase.($this->smtpCode===null?'':'／SMTP応答: '.$this->smtpCode).($this->socketErrno===null?'':'／接続エラー番号: '.$this->socketErrno);}
}
