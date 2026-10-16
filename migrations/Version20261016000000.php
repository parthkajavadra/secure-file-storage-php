<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema — equivalent of Django migrations 0001_initial + 0002_publicsharelink
 * (plus the user table from django.contrib.auth and the refresh-token table).
 *
 * Written with the Schema API, so it works on SQLite, MySQL/MariaDB and PostgreSQL.
 */
final class Version20261016000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users, stored files, sharing, public share links and refresh tokens';
    }

    public function up(Schema $schema): void
    {
        $user = $schema->createTable('app_user');
        $user->addColumn('id', 'integer', ['autoincrement' => true]);
        $user->addColumn('username', 'string', ['length' => 150]);
        $user->addColumn('roles', 'json');
        $user->addColumn('password', 'string', ['length' => 255]);
        $user->setPrimaryKey(['id']);
        $user->addUniqueIndex(['username'], 'UNIQ_APP_USER_USERNAME');

        $file = $schema->createTable('stored_file');
        $file->addColumn('id', 'integer', ['autoincrement' => true]);
        $file->addColumn('owner_id', 'integer');
        $file->addColumn('file_path', 'string', ['length' => 255]);
        $file->addColumn('title', 'string', ['length' => 255]);
        $file->addColumn('uploaded_at', 'datetime_immutable');
        $file->addColumn('access_level', 'string', ['length' => 10]);
        $file->setPrimaryKey(['id']);
        $file->addIndex(['owner_id'], 'IDX_C339E77C7E3C61F9');
        $file->addForeignKeyConstraint('app_user', ['owner_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_C339E77C7E3C61F9');

        $shared = $schema->createTable('stored_file_shared_with');
        $shared->addColumn('file_id', 'integer');
        $shared->addColumn('user_id', 'integer');
        $shared->setPrimaryKey(['file_id', 'user_id']);
        $shared->addIndex(['file_id'], 'IDX_4639D74693CB796C');
        $shared->addIndex(['user_id'], 'IDX_4639D746A76ED395');
        $shared->addForeignKeyConstraint('stored_file', ['file_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_4639D74693CB796C');
        $shared->addForeignKeyConstraint('app_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_4639D746A76ED395');

        $link = $schema->createTable('public_share_link');
        $link->addColumn('id', 'integer', ['autoincrement' => true]);
        $link->addColumn('file_id', 'integer');
        $link->addColumn('token', 'string', ['length' => 36]);
        $link->addColumn('created_at', 'datetime_immutable');
        $link->addColumn('expires_at', 'datetime_immutable');
        $link->setPrimaryKey(['id']);
        $link->addUniqueIndex(['token'], 'UNIQ_PUBLIC_SHARE_LINK_TOKEN');
        $link->addIndex(['file_id'], 'IDX_11F9000593CB796C');
        $link->addForeignKeyConstraint('stored_file', ['file_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_11F9000593CB796C');

        $refresh = $schema->createTable('refresh_tokens');
        $refresh->addColumn('id', 'integer', ['autoincrement' => true]);
        $refresh->addColumn('refresh_token', 'string', ['length' => 128]);
        $refresh->addColumn('username', 'string', ['length' => 255]);
        $refresh->addColumn('valid', 'datetime');
        $refresh->setPrimaryKey(['id']);
        $refresh->addUniqueIndex(['refresh_token'], 'UNIQ_9BACE7E1C74F2195');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('refresh_tokens');
        $schema->dropTable('public_share_link');
        $schema->dropTable('stored_file_shared_with');
        $schema->dropTable('stored_file');
        $schema->dropTable('app_user');
    }
}
