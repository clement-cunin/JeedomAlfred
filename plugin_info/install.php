<?php

function alfred_install()
{
    $sql = file_get_contents(__DIR__ . '/../core/sql/install.sql');
    DB::Prepare($sql, [], DB::FETCH_TYPE_ROW);
}

function alfred_update()
{
    alfred_install();
}

function alfred_remove()
{
    $sql = file_get_contents(__DIR__ . '/../core/sql/remove.sql');
    DB::Prepare($sql, [], DB::FETCH_TYPE_ROW);
}
