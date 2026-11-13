<?php

namespace App\Database\Expressions;

/**
 * DATE_SUB(base, INTERVAL n UNIT). See DateAdd.
 */
final class DateSub extends DateAdd
{
    protected const string SIGN = '-';

    protected const string MYSQL_FUNCTION = 'DATE_SUB';
}
