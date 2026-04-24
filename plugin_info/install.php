<?php

function alfred_install()
{
    include_file('core', 'alfredMigration', 'class', 'alfred');
    alfredMigration::runPending();
}

function alfred_update()
{
    include_file('core', 'alfredMigration', 'class', 'alfred');
    alfredMigration::runPending();
}

function alfred_remove()
{
    $sql = file_get_contents(__DIR__ . '/../core/sql/remove.sql');
    DB::Prepare($sql, [], DB::FETCH_TYPE_ROW);
}
