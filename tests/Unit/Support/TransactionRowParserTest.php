<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\BankProfile;
use App\Support\BankStatement\TransactionRowParser;
use Tests\TestCase;

final class TransactionRowParserTest extends TestCase
{
    /**
     * @param  array<string, int>  $columns
     */
    private function makeProfile(string $statementType, array $columns, ?string $dateFormat = 'Y-m-d'): BankProfile
    {
        $config = ['columns' => $columns];
        if ($dateFormat !== null) {
            $config['date_format'] = $dateFormat;
        }

        return new BankProfile(['statement_type' => $statementType, 'config' => $config]);
    }

    /**
     * @param  array<string, int>  $columns
     */
    private function bankProfile(array $columns, ?string $dateFormat = 'Y-m-d'): BankProfile
    {
        return $this->makeProfile('bank', $columns, $dateFormat);
    }

    /**
     * @param  array<string, int>  $columns
     */
    private function creditCardProfile(array $columns, ?string $dateFormat = 'Y-m-d'): BankProfile
    {
        return $this->makeProfile('credit_card', $columns, $dateFormat);
    }

    public function test_parse_row_returns_valid_array_for_complete_row(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '12.50']);

        $this->assertNotNull($result);
        $this->assertEquals('2024-01-15', $result['date']->toDateString());
        $this->assertEquals('COFFEE SHOP', $result['description']);
        $this->assertEqualsWithDelta(12.50, $result['amount'], PHP_FLOAT_EPSILON);
        $this->assertNull($result['external_id']);
    }

    public function test_parse_row_returns_null_when_date_column_not_configured(): void
    {
        $bankProfile = $this->bankProfile(['description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '12.50']);

        $this->assertNull($result);
    }

    public function test_parse_row_returns_null_when_date_value_is_empty(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['', 'Coffee Shop', '12.50']);

        $this->assertNull($result);
    }

    public function test_parse_row_returns_null_when_description_is_empty(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', '', '12.50']);

        $this->assertNull($result);
    }

    public function test_parse_row_returns_null_when_no_amount_columns_configured(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '12.50']);

        $this->assertNull($result);
    }

    public function test_parse_row_flips_sign_for_credit_card_statement(): void
    {
        $bankProfile = $this->creditCardProfile(['date' => 0, 'description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '12.50']);

        $this->assertNotNull($result);
        $this->assertEquals(-12.50, $result['amount']);
    }

    public function test_parse_row_does_not_flip_sign_for_bank_statement(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '12.50']);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(12.50, $result['amount'], PHP_FLOAT_EPSILON);
    }

    public function test_parse_row_profile_date_format_takes_priority(): void
    {
        // 'd.m.Y' is not in the global SUPPORTED_DATE_FORMATS list
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2], 'd.m.Y');
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['15.01.2024', 'Coffee Shop', '12.50']);

        $this->assertNotNull($result);
        $this->assertEquals('2024-01-15', $result['date']->toDateString());
    }

    public function test_parse_row_falls_back_to_global_date_formats(): void
    {
        // Profile format 'd.m.Y' does not match; 'Y-m-d' in global formats should match
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2], 'd.m.Y');
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '12.50']);

        $this->assertNotNull($result);
        $this->assertEquals('2024-01-15', $result['date']->toDateString());
    }

    public function test_parse_row_normalises_description_to_uppercase_and_squishes_whitespace(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', '  coffee  shop  ', '12.50']);

        $this->assertNotNull($result);
        $this->assertEquals('COFFEE SHOP', $result['description']);
    }

    public function test_parse_row_strips_currency_symbols_from_amount(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        foreach (['$12.50', '£12.50', '€12.50', '¥12.50'] as $rawAmount) {
            $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', $rawAmount]);
            $this->assertNotNull($result, 'Expected non-null result for amount: '.$rawAmount);
            $this->assertEqualsWithDelta(12.50, $result['amount'], PHP_FLOAT_EPSILON, 'Expected 12.50 for amount: '.$rawAmount);
        }
    }

    public function test_parse_row_converts_parentheses_to_negative_amount(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '(50.00)']);

        $this->assertNotNull($result);
        $this->assertEquals(-50.0, $result['amount']);
    }

    public function test_parse_row_returns_null_for_non_numeric_amount(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', 'N/A']);

        $this->assertNull($result);
    }

    public function test_parse_row_debit_only_row_produces_negative_amount(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '100.00', '']);

        $this->assertNotNull($result);
        $this->assertEquals(-100.0, $result['amount']);
    }

    public function test_parse_row_credit_only_row_produces_positive_amount(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '', '50.00']);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(50.0, $result['amount'], PHP_FLOAT_EPSILON);
    }

    public function test_parse_row_only_debit_column_configured_uses_zero_for_credit(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'debit' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '100.00']);

        $this->assertNotNull($result);
        $this->assertEquals(-100.0, $result['amount']);
    }

    public function test_parse_row_only_credit_column_configured_uses_zero_for_debit(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'credit' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '50.00']);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(50.0, $result['amount'], PHP_FLOAT_EPSILON);
    }

    public function test_parse_row_returns_null_when_description_is_not_provided(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'credit' => 1]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', '50.00']);

        $this->assertNull($result);
    }

    public function test_parse_row_returns_null_when_date_column_unconfigured_regardless_of_row_content(): void
    {
        // The date guard ($dateIndex === null → return null) must fire before accessing $row[$dateIndex].
        // Row has a valid date at the empty-string key (PHP's null array key). If the guard is removed,
        // $row[null] resolves to that value and parseRow would succeed — so assertNull kills the mutant.
        $bankProfile = $this->bankProfile(['description' => 1, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        /** @var array<int, string|null> $row */
        $row = ['' => '2024-01-15', 1 => 'Coffee Shop', 2 => '10.00'];
        $result = $transactionRowParser->parseRow($row);

        $this->assertNull($result);
    }

    public function test_parse_row_returns_null_when_date_value_is_zero_even_if_format_would_accept_it(): void
    {
        // The '0' guard ($dateString === '0' → return null) must reject before parseDate is called.
        // With format 'G' (24-hour, 0–23), Carbon::createFromFormat('G', '0') succeeds (returns midnight),
        // so if the guard is absent parseRow would return a result — assertNull kills the mutant.
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'amount' => 2], 'G');
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['0', 'Coffee Shop', '10.00']);

        $this->assertNull($result);
    }

    public function test_parse_row_returns_null_when_description_column_unconfigured_regardless_of_row_content(): void
    {
        // The description guard ($descriptionIndex === null → return null) must fire before accessing $row[$descriptionIndex].
        // Row has a valid description at the empty-string key (PHP's null array key). If the guard is removed,
        // $row[null] resolves to that value and parseRow would succeed — so assertNull kills the mutant.
        $bankProfile = $this->bankProfile(['date' => 0, 'amount' => 2]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        /** @var array<int, string|null> $row */
        $row = [0 => '2024-01-15', '' => 'Coffee Shop', 2 => '10.00'];
        $result = $transactionRowParser->parseRow($row);

        $this->assertNull($result);
    }

    public function test_parse_row_strips_whitespace_from_debit_column_before_parsing(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', ' 100.00 ', '']);

        $this->assertNotNull($result);
        $this->assertEquals(-100.0, $result['amount']);
    }

    public function test_parse_row_strips_whitespace_from_credit_column_before_parsing(): void
    {
        $bankProfile = $this->bankProfile(['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3]);
        $transactionRowParser = new TransactionRowParser($bankProfile);

        $result = $transactionRowParser->parseRow(['2024-01-15', 'Coffee Shop', '', ' 50.00 ']);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(50.0, $result['amount'], PHP_FLOAT_EPSILON);
    }
}
