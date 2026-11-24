<?php

declare(strict_types=1);

namespace CodeMatrix\\IntegrationInspector\\Storage;

/**
 * Creates the initial MySQL schema. Safe to run repeatedly.
 */
final class SchemaInstaller
{
    private object $connection;

    public function __construct(object $connection)
    {
        if (!method_exists($connection, 'queryExecute')) {
            throw new \\InvalidArgumentException('Connection must provide queryExecute().');
        }

        $this->connection = $connection;
    }

    public function install(): void
    {
        $this->connection->queryExecute(
            "CREATE TABLE IF NOT EXISTS b_cmi_event (
                EVENT_ID CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                SOURCE_CODE VARCHAR(64) NOT NULL,
                OPERATION VARCHAR(128) NOT NULL,
                DIRECTION VARCHAR(12) NOT NULL,
                STATUS VARCHAR(12) NOT NULL,
                CORRELATION_ID VARCHAR(128) NULL,
                PARENT_EVENT_ID CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
                DATE_START DATETIME NOT NULL,
                OCCURRED_AT DATETIME NULL,
                DATE_FINISH DATETIME NULL,
                DURATION_MS BIGINT UNSIGNED NULL,
                HTTP_STATUS SMALLINT UNSIGNED NULL,
                RESULT_CODE VARCHAR(64) NULL,
                ERROR_CLASS VARCHAR(255) NULL,
                ERROR_CODE VARCHAR(64) NULL,
                ERROR_MESSAGE VARCHAR(1000) NULL,
                ERROR_FILE VARCHAR(500) NULL,
                ERROR_LINE INT UNSIGNED NULL,
                PRIMARY KEY (EVENT_ID),
                KEY IX_CMI_EVENT_DATE (DATE_START),
                KEY IX_CMI_EVENT_SOURCE_DATE (SOURCE_CODE, DATE_START),
                KEY IX_CMI_EVENT_STATUS_DATE (STATUS, DATE_START),
                KEY IX_CMI_EVENT_DIRECTION_DATE (DIRECTION, DATE_START),
                KEY IX_CMI_EVENT_CORRELATION (CORRELATION_ID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->connection->queryExecute(
            "CREATE TABLE IF NOT EXISTS b_cmi_event_data (
                EVENT_ID CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                REQUEST_META MEDIUMTEXT NULL,
                RESPONSE_META MEDIUMTEXT NULL,
                CONTEXT MEDIUMTEXT NULL,
                REQUEST_PAYLOAD MEDIUMTEXT NULL,
                RESPONSE_PAYLOAD MEDIUMTEXT NULL,
                ERROR_TRACE MEDIUMTEXT NULL,
                PRIMARY KEY (EVENT_ID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->connection->queryExecute(
            "CREATE TABLE IF NOT EXISTS b_cmi_source (
                CODE VARCHAR(64) NOT NULL,
                NAME VARCHAR(255) NOT NULL,
                DESCRIPTION VARCHAR(1000) NULL,
                DATE_CREATE DATETIME NOT NULL,
                DATE_UPDATE DATETIME NOT NULL,
                PRIMARY KEY (CODE)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
