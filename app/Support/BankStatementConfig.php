<?php

declare(strict_types=1);

namespace App\Support;

class BankStatementConfig
{
    const int MAX_FILE_SIZE_MB = 2;

    const int|float MAX_FILE_SIZE_KB = self::MAX_FILE_SIZE_MB * 1024;

    const int JOB_TIMEOUT_SECONDS = 60;

    const int JOB_MAX_TRIES = 3;

    // Date parsing formats (in order of preference)
    const array SUPPORTED_DATE_FORMATS = [
        'd/m/Y',
        'Y-m-d',
        'm/d/Y',
        'd-m-Y',
    ];

    const true CSV_HAS_HEADER_DEFAULT = true;

    const string STATEMENT_TYPE_BANK = 'bank';

    const string STATEMENT_TYPE_CREDIT_CARD = 'credit_card';

    const array VALID_STATEMENT_TYPES = [
        self::STATEMENT_TYPE_BANK,
        self::STATEMENT_TYPE_CREDIT_CARD,
    ];

    const string STATUS_UPLOADED = 'uploaded';

    const string STATUS_PARSING = 'parsing';

    const string STATUS_PARSED = 'parsed';

    const string STATUS_FAILED = 'failed';

    const string STATUS_COMMITTED = 'committed';

    const int TRANSACTION_CHUNK_SIZE = 1000;

    const string HASH_ALGORITHM = 'sha1';

    const int AMOUNT_DECIMAL_PLACES = 2;
}
