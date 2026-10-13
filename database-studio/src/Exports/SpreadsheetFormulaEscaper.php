<?php

namespace DatabaseStudio\Exports;

class SpreadsheetFormulaEscaper
{
    public static function escapeCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (! self::isFormulaLike($value)) {
            return $value;
        }

        return "'".$value;
    }

    public static function escapeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            $row[$key] = self::escapeCell($value);
        }

        return $row;
    }

    private static function isFormulaLike(string $value): bool
    {
        if (preg_match('/^[\t\r\n]/', $value) === 1) {
            return true;
        }

        $trimmed = ltrim($value, " \t\r\n");
        if ($trimmed === '' || is_numeric($trimmed)) {
            return false;
        }

        return preg_match('/^[=+\-@]/', $trimmed) === 1;
    }
}
