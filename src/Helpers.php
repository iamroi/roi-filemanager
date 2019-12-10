<?php

namespace Iamroi\FileManager;

use League\Flysystem\Util;

class Helpers
{
    /**
     * Returns a safe filename, for a given platform (OS), by replacing all
     * dangerous characters with an underscore.
     *
     * @return Boolean string A safe version of the input filename
     */
    public static function sanitizeFileName($dangerousFilename)
    {
        $dangerousCharacters = array(" ", '"', "'", "&", "/", "\\", "?", "#", ":");

        // every forbidden character is replace by an underscore
        return str_replace($dangerousCharacters, '_', $dangerousFilename);
    }

    public static function uniqueFileName($dir, $filename)
    {
        // Sanitize the file name before we begin processing.
        $filename = self::sanitizeFileName( $filename );

        $number = '';

        // Separate the filename into a name and extension.
        $ext  = pathinfo( $filename, PATHINFO_EXTENSION );
//    $name = pathinfo( $filename, PATHINFO_BASENAME );
        if ( $ext ) {
            $ext = '.' . $ext;
        }

        // Edge case: if file is named '.ext', treat as an empty name.
//        if ( $name === $ext ) {
//            $name = '';
//        }

        while ( file_exists( $dir . "/$filename" ) ) {
            $new_number = (int) $number + 1;
            if ( '' == "$number$ext" ) {
                $filename = "$filename-" . $new_number;
            } else {
                $filename = str_replace( array( "-$number$ext", "$number$ext" ), '-' . $new_number . $ext, $filename );
            }
            $number = $new_number;
        }

        return $filename;
    }

    public static function getFileManagerItemUrl($base, $fileManagerDir, $uri)
    {
//    $base = '//'.env('APP_API_HOST');
//        $base = asset('storage');
//    dd($host);
        return implode('/', array_filter([$base, $fileManagerDir, ltrim($uri, '\\/')], 'strlen'));
    }

    public static function cleanFilePath($path)
    {
        $path = self::sanitize($path);

//        $path = preg_replace( '/^\W*(.*?)\W*$/', '$1', $path );
        $path = Util::normalizePath($path);
//        $path = rtrim(ltrim($path, '\\/'), '\\/');
        // remove trailing spaces from parts of the path
        $pathParts = array_map(function ($pathPart) {
            return trim($pathPart);
        }, explode('/', $path));

        // clean out empty parts and
        // join them
        $path = implode('/', array_filter($pathParts, 'strlen'));

        return $path;
    }

    public static function isMultipleFileUpload($uploadName)
    {
        return isset($_FILES[$uploadName]) && is_array($_FILES[$uploadName]['name']);
    }

    /**
     * Function: sanitize
     * Returns a sanitized string, typically for URLs.
     *
     * Parameters:
     *     $string - The string to sanitize.
     *     $force_lowercase - Force the string to lowercase?
     *     $removeAlphaNumeric - If set to *true*, will remove all non-alphanumeric characters.
     */
    public static function sanitize($string, $force_lowercase = false, $removeAlphaNumeric = false) {
//        $strip = array("~", "`", "!", "@", "#", "$", "%", "^", "&", "*", "(", ")", "=", "+", "[", "{", "]",
//            "}", "\\", "|", ";", ":", "\"", "'", "&#8216;", "&#8217;", "&#8220;", "&#8221;", "&#8211;", "&#8212;",
//            "â€”", "â€“", ",", "<", ">", "?"); //"/", "_", ".",

        //^<>:"\\\|?*

        $strip = array("^", "*", "\\", "|", ":", "\"", '"', "<", ">", "?"); //"/", "_", ".",
        $clean = trim(str_replace($strip, "", strip_tags($string)));
//        $clean = preg_replace('/\s+/', "-", $clean);
        $clean = ($removeAlphaNumeric) ? preg_replace("/[^a-zA-Z0-9]/", "", $clean) : $clean ;
        return ($force_lowercase) ?
            (function_exists('mb_strtolower')) ?
                mb_strtolower($clean, 'UTF-8') :
                strtolower($clean) :
            $clean;
    }
}
