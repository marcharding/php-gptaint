<?php

namespace App\Service;

class TaintTypes
{
    public const TaintedCallable = 243;
    public const TaintedCookie = 257;
    public const TaintedCustom = 249;
    public const TaintedEval = 252;
    public const TaintedFile = 255;
    public const TaintedHeader = 256;
    public const TaintedHtml = 245;
    public const TaintedInclude = 251;
    public const TaintedLdap = 254;
    public const TaintedSSRF = 253;
    public const TaintedShell = 246;
    public const TaintedSql = 244;
    public const TaintedSystemSecret = 248;
    public const TaintedTextWithQuotes = 274;
    public const TaintedUnserialize = 250;
    public const TaintedUserSecret = 247;
    public const TaintedUnkown = 999;

    public static function getIdByName($issueId)
    {
        if (defined('self::'.$issueId)) {
            return constant('self::'.$issueId);
        } else {
            return null;
        }
    }

    public static function getNameById($issueId)
    {
        $constants = self::getConstants();
        $constantName = array_search($issueId, $constants);

        return $constantName !== false ? $constantName : null;
    }

    public static function getConstants()
    {
        $oClass = new \ReflectionClass(__CLASS__);

        return $oClass->getConstants();
    }
}
