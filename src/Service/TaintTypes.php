<?php

namespace App\Service;

class TaintTypes
{
    const TaintedCallable = 243;
    const TaintedCookie = 257;
    const TaintedCustom = 249;
    const TaintedEval = 252;
    const TaintedFile = 255;
    const TaintedHeader = 256;
    const TaintedHtml = 245;
    const TaintedInclude = 251;
    const TaintedLdap = 254;
    const TaintedSSRF = 253;
    const TaintedShell = 246;
    const TaintedSql = 244;
    const TaintedSystemSecret = 248;
    const TaintedTextWithQuotes = 274;
    const TaintedUnserialize = 250;
    const TaintedUserSecret = 247;

    public static function getIdByName($issueId)
    {
        if (defined('self::' . $issueId)) {
            return constant('self::' . $issueId);
        } else {
            return null;
        }
    }

    public static function getNameById($issueId)
    {
        $constants = get_defined_constants(true)['user'];
        $constantName = array_search($issueId, $constants);

        return $constantName !== false ? $constantName : null;
    }

}