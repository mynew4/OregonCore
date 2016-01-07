<?php
/*
 * This file is part of the OregonCore Project. See AUTHORS file for Copyright information
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation; either version 2 of the License, or (at your
 * option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program. If not, see <http://www.gnu.org/licenses/>.
 */

// This file generates database documentation for doxygen.
// Doxygen eneeds to be run twice - first to generate xml
// then after calling this script to generate html

ini_set("precision", "4");

define ("MYSQL_DSN", "mysql:host=127.0.0.1");
define ("MYSQL_USR", "root");
define ("MYSQL_PWD", "root");
define ("WRAPPER_NAMESPACE", "Database::");
define ("TABSIZE", 4);

use function sprintf as f;

$schemas = array ("realmd", "characters", "world");
$Outputs = array ();

$FillSpace = "FillSpace"; // reference to function
$MakeRef = "MakeRef";
$xmlindex = "../../../build/doc/xml/index.xml";

try
{
    // ------------------------------------------------
    // Parse XML file generated by doxygen's xml output
    // ------------------------------------------------

    //libxml_use_internal_errors(false);
    $doxyData = simplexml_load_file($xmlindex);

    // -------------
    // Connect to db
    // -------------

    $db = new PDO(MYSQL_DSN, MYSQL_USR, MYSQL_PWD, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                                         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH));
    $db->Exec("set character set utf8");
    $db->Exec("set names utf8 collate utf8_general_ci");

    // -------------------------------
    // Prefetch all documnetation data
    // -------------------------------

    $schema_doc = array();
    $table_doc = array();
    $column_doc = array();

    $result = $db->Query("SELECT `schema`, `brief`, `details` FROM `documentation`.`schema`")->fetchAll();
    foreach ($result as $row)
    {
        $schema_doc[$row["schema"]] = $row;
        $table_doc[$row["schema"]] = array();
        $column_doc[$row["schema"]] = array();
    }
        
    $result = $db->Query("SELECT `schema`, `table`, `brief`, `details` FROM `documentation`.`table`")->fetchAll();
    foreach ($result as $row)
    {
        $table_doc[$row["schema"]][$row["table"]] = $row;
        $column_doc[$row["schema"]][$row["table"]] = array();
    }

    $result = $db->Query("SELECT `schema`, `table`, `column`, `brief`, `details`, `reference` FROM `documentation`.`column`")->fetchAll();
    foreach ($result as $row)
        $column_doc[$row["schema"]][$row["table"]][$row["column"]] = $row;

    // -------------------------------

    foreach ($schemas as $schema)
    {
        $db->Exec(f("USE %s", $schema));
        $Output = new Output_T(MakeRef($schema));

        // -----------------------
        // Create index for schema
        // -----------------------

        $Output->Append("@page {$MakeRef($schema)} $schema\n");

        if (isset($schema_doc[$schema]))
        {
            $brief = strval($schema_doc[$schema]["brief"]);
            $details = strval($schema_doc[$schema]["details"]);

            if (!empty($brief))
                $Output->Append("<p>$brief</p>\n");
            if (!empty($details))
                $Output->Append("<p>$details</p>\n");
        }

        // ---------------
        // Add table index
        // ---------------

        $Output->Append("<table>\n");
        $Output->Append("<tr><th><strong>Table</strong></th><th><strong>Description</strong></th><th><strong>Doc Status</strong></th></tr>\n");
        
        $tables = $db->Query("SHOW TABLE STATUS")->fetchAll();
        $tables_simplified = array();
        foreach ($tables as $table)
        {
            $tables_simplified[] = $table["Name"];

            $Output->Append("<tr>");
            if (isset($table_doc[$schema][$table["Name"]]))
                $Output->Append("<td>@subpage {$MakeRef($schema, $table["Name"])} \"{$table["Name"]}\" </td><td>{$table_doc[$schema][$table["Name"]]["brief"]}</td>");
            else
                $Output->Append("<td>@subpage {$MakeRef($schema, $table["Name"])} \"{$table["Name"]}\" </td><td></td>");

            $cols_in_table   = $db->Query("SELECT COLUMN_NAME FROM `information_schema`.`columns` WHERE `TABLE_SCHEMA` = '$schema' AND `TABLE_NAME` = {$db->Quote($table["Name"])}")->fetchAll(PDO::FETCH_NUM);
            $documented_cols = $db->Query("SELECT `column` FROM `documentation`.`column` WHERE `schema` = '$schema' AND `table` = {$db->Quote($table["Name"])}")->fetchAll(PDO::FETCH_NUM);

            $real_documented_cols = 0;
            $cols_in_table_simplified = array();
            foreach($cols_in_table as $col)
                $cols_in_table_simplified[] = $col[0];
            
            foreach ($documented_cols as $col)
            {
                if (!in_array($col[0], $cols_in_table_simplified))
                    print "WARNING: Column {$col[0]} is documented for table {$table["Name"]} but doesn't exist!\n";
                else
                    ++$real_documented_cols;
            }

            $pct = ($real_documented_cols / count($cols_in_table)) * 100;
            //if (ceil($pct) >= 100 && $real_documented_cols < count($cols_in_table))
            //    $pct = 99;

            $Output->Append("<td>$pct%</td>");
            $Output->Append("</tr>\n");
        }
        $Output->Append("\n</table>\n");
        
        foreach (array_keys($table_doc[$schema]) as $doc_table)
            if (!in_array($doc_table, $tables_simplified))
                print "WARNING: Table $doc_table is documented but doesn't exist!\n";

        // -----------------
        // Queue for writing
        // -----------------

        $Outputs[] = $Output;

        // -------------------------------------
        // Generate all tables within the schema
        // -------------------------------------

        foreach ($tables as $table)
        {
            $Output = new Output_T(MakeRef($schema, $table["Name"]));

            $Output->Append("@page {$MakeRef($schema, $table["Name"])} {$table["Name"]}\n");
            if (isset($table_doc[$schema][$table["Name"]]))
            {
                $brief = strval($table_doc[$schema][$table["Name"]]["brief"]);
                $details = strval($table_doc[$schema][$table["Name"]]["details"]);

                if (!empty($brief))
                    $Output->Append("<p>$brief</p>\n");
                if (!empty($details))
                    $Output->Append("<p>$details</p>\n");
            }

            // --------------------
            // Create columns index
            // --------------------

            $columns = $db->Query("SHOW FULL COLUMNS FROM `{$schema}`.`{$table["Name"]}`")->fetchAll();
            $keys = array();

            $Output->Append("@section structure_doc_ Table Structure\n\n");
            $Output->Append("<table>\n");
            $Output->Append("<tr><th><strong>Column</strong></th>");
            $Output->Append("<th><strong>Data Type</strong></th>");
            $Output->Append("<th><strong>Null</strong></th>");
            $Output->Append("<th><strong>Default</strong></th>");
            $Output->Append("<th><strong>Extra</strong></th>");
            $Output->Append("<th><strong>Key</strong></th>");
            $Output->Append("<th><strong>Collation</strong></th>");
            $Output->Append("<th><strong>Brief Description</strong></th></tr>\n");
            foreach ($columns as $column)
            {
                $comment = "";
                $default = $column["Default"];
                if (is_null($default))
                    $default = "(null)";
                $Output->Append("<tr>");
                $Output->Append("<td>@ref {$MakeRef($schema, $table["Name"], $column["Field"])} </td>");
                $Output->Append("<td>{$column["Type"]}</td>");
                $Output->Append("<td>{$column["Null"]}</td>");
                $Output->Append("<td>{$default}</td>");
                $Output->Append("<td>{$column["Extra"]}</td>");
                $Output->Append("<td>{$column["Key"]}</td>");
                $Output->Append("<td>{$column["Collation"]}</td>");
                if (isset($column_doc[$schema][$table["Name"]][$column["Field"]]))
                    $Output->Append("<td>{$column_doc[$schema][$table["Name"]][$column["Field"]]["brief"]}</td>");
                else
                    $Output->Append("<td></td>");
                $Output->Append("</tr>\n");
            }
            $Output->Append("</table>\n");
            $Output->Append("@section field_doc_ Description of the Fields\n");

            //foreach (array("Engine", "Row_format", "Data_length", "Index_length", "Auto_Increment", "Collation") as $data)
            //{
            //    if (isset($table[$data]))
            //        $details .= "\n \\n $data: {$table[$data]}";
            //    else
            //        $details .= "\n \\n $data: n/a";
            //}

            foreach ($columns as $column)
            {
                $Output->Append("\n\n@subsection {$MakeRef($schema, $table["Name"], $column["Field"])} {$column["Field"]}\n\n");

                if (isset($column_doc[$schema][$table["Name"]][$column["Field"]]))
                {
                    $brief = strval($column_doc[$schema][$table["Name"]][$column["Field"]]["brief"]);
                    $details = strval($column_doc[$schema][$table["Name"]][$column["Field"]]["details"]);
                    if (!empty($brief))
                        $Output->Append("<p>$brief</p>\n");
                    if (!empty($details))
                        $Output->Append("<p>$details</p>\n");
                    $reference = strval($column_doc[$schema][$table["Name"]][$column["Field"]]["reference"]);
                    if (!empty($reference))
                    {
                        $Output->Append("C++ Reference - @ref $reference :\n");
                        // I HATE ALL XPATH AND XML CODE!!! , but at least it works :)
                        foreach ($doxyData->xpath("compound/member[name = '$reference']/parent::*") as $compound);
                        {
                            foreach ($compound->member as $member)
                            {
                                if (strval($member->name) == $reference)
                                {
                                    $fileRef = simplexml_load_file(dirname($xmlindex) . "/" . $compound->attributes()->refid . ".xml");
                                    if ($values = $fileRef->xpath("//memberdef[@id='{$member->attributes()->refid}']/enumvalue"))
                                    {
                                        $Output->Append("<table>");
                                        $Output->Append("<tr><th><strong>Name</strong></th><th><strong>Value</strong></th><th><strong>Description</strong></th></tr>\n");
                                        foreach ($values as $enumValue)
                                        {
                                            //var_dump($enumValue);
                                            //exit (0);
                                            $value = trim(str_replace("=", "", $enumValue->initializer));
                                            $brief = trim($enumValue->briefdescription);
                                            $details = trim($enumValue->detaileddescription);
                                            $Output->Append("<tr><td>" . strval($enumValue->name) . "</td><td>{$value}</td><td>{$brief} {$details}</td></tr>\n");
                                        }
                                        $Output->Append("</tr></table>");
                                    }
                                }
                            }
                        }
                    }
                }
                else
                    $Output->Append("There is no documentation for this field, yet.");
            }

            // -----------------
            // Queue for writing
            // -----------------

            $Outputs[] = $Output;
        }
    }
}
catch (Output_T $e)
{
    printf ("Fatal Error: {$e->getMessage()}\n");
    var_dump(debug_backtrace());
    exit (-1);
}

// --------------------
// Write queued outputs
// --------------------

foreach ($Outputs as $Output)
{
    $ok = file_put_contents($Output->filename, strval($Output), LOCK_EX);
    if ($ok)
        printf ("File %s was generated.\n", $Output->filename);
    else
        printf ("File %s was not generated.\n");
}

// ------------
// Helper class
// ------------

class Output_T
{
    public $filename;
    private $body = "";
    
    function __construct($filename)
    {
        $this->filename = "$filename.dox";
    }
    
    function Append()
    {
        $args = func_get_args();
        $fmt = array_shift($args);
 
        if (count($args))
            $this->body .= vsprintf($fmt, $args);
        else
            $this->body .= $fmt;
    }
    
    function __toString()
    {
        return "/**\n{$this->body}\n */";
    }
}

function FillSpace($string)
{
    return preg_replace_callback("~[\W]~",
                function ($a)
                { 
                    return '_'; //($n = in_array("0123456789", $a[0]) ? "abcdefghij"[$n] : '_';
                }, $string);
}

function MakeRef($schema, $table = NULL, $column = NULL)
{
    global $FillSpace;
    $ref = "schema_{$FillSpace($schema)}";
    if (!is_null($table))
        $ref .= "_table_{$FillSpace($table)}";
    if (!is_null($column))
        $ref .= "_column_{$FillSpace($column)}";
    return strtolower($ref);
}
