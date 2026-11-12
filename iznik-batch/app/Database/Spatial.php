<?php

namespace App\Database;

use App\Database\Expressions\CastAs;
use App\Database\Expressions\JsonExtract;
use App\Database\Expressions\MbrContains;
use App\Database\Expressions\MbrIntersects;
use App\Database\Expressions\MbrWithin;
use App\Database\Expressions\Point;
use App\Database\Expressions\StGeometryType;
use App\Database\Expressions\StSrid;
use App\Database\Expressions\Value;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Support\Facades\DB;

/**
 * Spatial SQL fragments for hand-written (raw) statements, rendered for the connection's own dialect.
 * The fragments come from the expression classes in App\Database\Expressions, so a raw statement and a
 * query-builder one spell a spatial function the same way. Operands are SQL text (a column, or `?`),
 * embedded as written.
 */
final class Spatial
{
    /** Renders an expression for the default connection's grammar. */
    public static function sql(ExpressionContract $expression): string
    {
        return (string) $expression->getValue(DB::getQueryGrammar());
    }

    /** A point from two SQL operands, labelled with the SRID: ST_SRID(POINT(x, y), srid) on MySQL. */
    public static function point(string $x, string $y, int|string $srid): string
    {
        return self::sql(new StSrid(new Point(DB::raw($x), DB::raw($y)), DB::raw((string) $srid)));
    }

    /** MBRContains(a, b) from two SQL operands: MBRContains on MySQL, the bounding-box operator on PostgreSQL. */
    public static function mbrContains(string $a, string $b): string
    {
        return self::sql(new MbrContains(DB::raw($a), DB::raw($b)));
    }

    /** MBRWithin(a, b) from two SQL operands. */
    public static function mbrWithin(string $a, string $b): string
    {
        return self::sql(new MbrWithin(DB::raw($a), DB::raw($b)));
    }

    /** MBRIntersects(a, b) from two SQL operands. */
    public static function mbrIntersects(string $a, string $b): string
    {
        return self::sql(new MbrIntersects(DB::raw($a), DB::raw($b)));
    }

    /** The geometry's type name as MySQL spells it ('POINT', 'POLYGON'), from a SQL operand (a column). */
    public static function geometryType(string $column): string
    {
        return self::sql(new StGeometryType(DB::raw($column)));
    }

    /**
     * The point a member is measured from: their chosen location (settings.mylocation) when they have one,
     * else the coordinates of their last location row. $settings, $lng and $lat are SQL operands (columns);
     * $extraCondition is an extra SQL condition the chosen location needs (for example that the member is
     * not a partner account); $sridSql is the SRID operand (a literal or `?`).
     */
    public static function memberPoint(string $settings, string $lng, string $lat, string $sridSql, string $extraCondition = ''): string
    {
        $path = fn (string $leaf): string => self::sql(new JsonExtract(DB::raw($settings), Value::of('$.mylocation.'.$leaf)));
        $number = fn (string $leaf): string => self::sql(new CastAs(DB::raw($path($leaf)), 'DECIMAL', 10, 6));
        $when = $path('lat').' IS NOT NULL AND '.$path('lng').' IS NOT NULL'.$extraCondition;
        $x = 'CASE WHEN '.$when.' THEN '.$number('lng').' ELSE '.$lng.' END';
        $y = 'CASE WHEN '.$when.' THEN '.$number('lat').' ELSE '.$lat.' END';

        return self::sql(new StSrid(new Point(DB::raw($x), DB::raw($y)), DB::raw($sridSql)));
    }
}
