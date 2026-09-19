<?php
/**
 * Thrown by InfluencerJobService::create_job when the account cannot pay for the
 * job in AI credits (or has no plan). $limit carries what the API needs to answer:
 * need_credits / need_plan, balance, price.
 */
class PlanLimitException extends RuntimeException {

    public $limit = array();

    public function __construct($message, array $limit = array()){
        parent::__construct((string) $message);
        $this->limit = $limit;
    }
}
