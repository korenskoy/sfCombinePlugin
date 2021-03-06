<?php

/**
 * Utility class of static methods for sfCombine
 *
 * @package     sfCombine
 * @subpackage  sfCombineUtility
 * @author      Alexandre Mogère
 * @author      Kevin Dew <kev@dewsolutions.co.uk>
 */
class sfCombineUtility
{
    /**
     * Check whether or not a file is combinable. Reasons for files not being
     * combinable are, a url with a protocol (likely to be a different server),
     * a file path in full or everything before the question mark is in the
     * $doNotCombine array, or if the file does not exist (could be a dynamic
     * file)
     *
     * @param string $file            File name
     * @param array  $doNotCombine    (Optional) Array of files not to combine.
     *                                Default empty array.
     *
     * @return  bool
     */
    public static function combinableFile($file, array $doNotCombine = []): bool
    {
        // check for a remote or file we've specified not to combine
        if (
          strpos($file, '://')
          ||
          self::skipAsset($file, $doNotCombine)
        ) {
            return false;
        }

        // remove anything past the question mark
        $fileParts = explode('?', $file);
        $file      = $fileParts[0];

        if (self::skipAsset($file, $doNotCombine)) {
            return false;
        }

        // check absolute file exists
        if (
          (0 === strpos($file, '/'))
          && !self::getFilePath($file)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Get the last modified timestamp from a file
     *
     * @param string $file
     * @param mixed   Method used to retrieve the asset path
     *
     * @return  int
     */
    public static function getModifiedTimestamp($file, $assetPathMethod): int
    {
        // prefix asset path (if applicable)
        if ($assetPathMethod && is_callable($assetPathMethod)) {
            $file = $assetPathMethod($file);
        }

        if (!self::combinableFile($file, [])) {
            return 0;
        }

        $fileParts = explode('?', $file);
        $file      = $fileParts[0];
        $filePath  = self::getFilePath($file);

        if ($filePath) {
            $lastModified = filemtime($filePath);

            if ($lastModified) {
                return $lastModified;
            }
        }

        return 0;
    }

    /**
     * Get the path to a file as long as the file exists.
     *
     * @param string $file
     *
     * @return  string|false  False if file doesn't exist
     */
    public static function getFilePath($file)
    {
        $paths = array(
          sfConfig::get('sf_web_dir') . $file,
          sfConfig::get('sf_symfony_data_dir') . '/web' . $file
        );

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return false;
    }

    /**
     * Normalize a path (get rid of relatives)
     *
     * based on: http://stackoverflow.com/questions/4049856/replace-phps-realpath
     *
     * @static
     *
     * @param string $path
     *
     * @return mixed
     */
    public static function normalizePath($path)
    {
        if ($path === '') {
            return $path;
        }

        $normalizedPath = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
        $parts          = array_filter(explode(DIRECTORY_SEPARATOR, $normalizedPath), 'strlen');
        $absolutes      = [];
        foreach ($parts as $part) {
            if ('.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        $normalizedPath = implode(DIRECTORY_SEPARATOR, $absolutes);

        return strpos($path, '/') === 0 ? '/' . $normalizedPath : $normalizedPath;
    }

    /**
     * Whether or not this is a file that should be skipped
     *
     * @param string $file
     * @param array  $doNotCombine
     *
     * @return  bool
     */
    public static function skipAsset($file, array $doNotCombine = []): bool
    {
        return
          in_array($file, $doNotCombine, true)
          ||
          in_array(basename($file), $doNotCombine, true)
          ||
          self::skipByRegexp($file, $doNotCombine);
    }

    /**
     * Whether or not this is a file that should be skipped
     *
     * @param string $file
     * @param array  $doNotCombine
     *
     * @return  bool
     */
    public static function skipByRegexp($file, array $doNotCombine = []): bool
    {
        foreach ($doNotCombine as $pattern) {
            if (@preg_match($pattern, $file) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the cache directory for sfCombine
     *
     * @return string
     */
    public static function getCacheDir(): string
    {
        return sfConfig::get('sf_cache_dir') . '/'
          . sfConfig::get('app_sfCombinePlugin_cache_dir', 'sfCombine');
    }

    /**
     * Send GZip headers if possible
     *
     * @return  void
     * @author  Alexandre Mogère
     */
    public static function setGzip()
    {
        // gzip compression
        if (
          sfConfig::get('app_sfCombinePlugin_gzip', true)
          && !self::_checkGzipFail()
          && !self::_checkGzipAlreadyStarted()
        ) {
            ob_start('ob_gzhandler');
        }
    }

    /**
     * Send cache headers if possible.
     *
     * @param sfResponse $response
     *
     * @author  Alexandre Mogère
     */
    public static function setCacheHeaders($response)
    {
        $max_age = sfConfig::get('app_sfCombinePlugin_client_cache_max_age', false);

        if ($max_age !== false) {
            $lifetime = $max_age * 86400; // 24*60*60
            $response->addCacheControlHttpHeader('max-age', $lifetime);
            $response->setHttpHeader(
              'Pragma',
              sfConfig::get('app_sfCombinePlugin_pragma_header', 'public')
            );
            $response->setHttpHeader(
              'Expires',
              $response->getDate(time() + $lifetime)
            );
        }
    }

    /**
     * Check whether we can send gzip
     *
     * @return  bool
     * @author  Alexandre Mogère
     */
    protected static function _checkGzipFail(): bool
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'];

        if (
          strpos($userAgent, 'Mozilla/4.0 (compatible; MSIE ') !== 0
          ||
          strpos($userAgent, 'Opera') !== false
        ) {
            return false;
        }

        $version = (float)substr($userAgent, 30);

        return $version < 6 || ($version === 6.0 && strpos($userAgent, 'SV1') === false);
    }

    /**
     * Check whether we can start gzip handler
     *
     * @return  bool
     */
    protected static function _checkGzipAlreadyStarted(): bool
    {
        return in_array('ob_gzhandler', ob_list_handlers(), true);
    }

    /**
     *
     * @param string $js
     *
     * @return  string
     */
    public static function minifyInlineJs($js): string
    {
        if (!sfConfig::get('app_sfCombinePlugin_enabled', false)) {
            return $js;
        }

        // minify content
        $config = sfConfig::get('app_sfCombinePlugin_js', []);

        $combinerClass = $config['combiner_class'] ?? 'sfCombineCombinerJs';

        $combiner = new $combinerClass($config);

        $js = $combiner->minify($js, ($config['inline_minify_method'] ?? false), ($config['inline_minify_method_options'] ?? []));

        return $js;
    }

    /**
     *
     * @param string $css
     *
     * @return  string
     */
    public static function minifyInlineCss($css): string
    {
        if (!sfConfig::get('app_sfCombinePlugin_enabled', false)) {
            return $css;
        }

        $config        = sfConfig::get('app_sfCombinePlugin_css', []);
        $combinerClass = $config['combiner_class'] ?? 'sfCombineCombinerCss';

        $combiner = new $combinerClass($config);

        $css = $combiner->minify($css, ($config['inline_minify_method'] ?? false), ($config['inline_minify_method_options'] ?? []));

        return $css;
    }
}
