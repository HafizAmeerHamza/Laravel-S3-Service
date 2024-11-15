<?php

namespace App\Http\Services\Common;

use App\Helpers\Constants;

use Illuminate\Support\Str;
use function App\Helpers\getFile;
use function App\Helpers\fileExist;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Storage;

class S3Service
{
    protected $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('s3');
    }

    /**
     * return the active s3 path based on the environment
     * return the path with live or staging prefix
    */
    public function getActiveS3Path($path)
    {
        $path = isset($path) ? trim(str_replace('/media/', '', $path), '/') : '';
        
        if(env('AWS_STAGING')) {
            $path = Constants::STORAGE_PREFIX['staging'] . $path;
        }
        else {
            $path = Constants::STORAGE_PREFIX['live'] . $path;
        }
        return $path;
    }

    /**
     * store file in s3 storage
     * store file for staging and live servers in different directores
     * return file name on success, otherwise false
     * options: path, fileName, visibility
     */
    public function storeFile($file, $options = [])
    {
        $visibility = isset($options['visibility']) ? $options['visibility'] : 'public';
        
        $path = $this->getActiveS3Path($options['path']);
        
        if(isset($options['fileName']) && !empty($options['fileName'])) {
            return $this->disk->putFileAs($path, $file, $options['fileName'], $visibility);
        }
        else{
            return $this->disk->put($path, $file, $visibility);
        }
    }

    /**
     * retrieve file from s3
     * return file content binary on success, otherwise false
     */
    public function retrieveFile($filename)
    {
        return $this->disk->get($filename);
    }

    /**
     * check if file exists on given path
     * return true if exists, otherwise false
     */
    public function fileExists($filename)
    {
        return $this->disk->exists($filename);
    }

    /**
     * delete file from s3
     * @param  string|array  $filename
     * @return bool
     */
    public function deleteFile($filename)
    {
        if(!empty($filename)){
            return $this->disk->delete($filename);
        }
        return false;
    }

    public function deleteDirectory($directory)
    {
        return $this->disk->deleteDirectory($directory);
    }

    /**
     * return the public url of the file
     */
    public function getPublicUrl($filename)
    {
        return $this->disk->url($filename);
    }

    /**
     * move file from local storage path to S3 bucket
     * return true on success, otherwise false
     */
    public function moveFileToS3($localFilePath, $s3Path, $visibility = 'public')
    {
        if(fileExist($localFilePath)) {
            $fileContent = getFile($localFilePath);

            $s3Path = $this->getActiveS3Path($s3Path);

            $this->disk->put($s3Path, $fileContent, $visibility);
            if($this->fileExists($s3Path)) {
                return $s3Path;
            }
        }
        return false;
    }

    function resizeAndStoreUploadedImage($image, $path, $width=230, $height=335)
    {
        if(!$path || !$image) {
            return false;
        }

        $fileName = 'thumbnail_' .$image->hashName();
        
        $resizedImage = Image::make($image)->resize($width, $height, function($constraint){
            $constraint->upsize();
        })->encode('jpg', 100);

        $filePath = $path . $fileName;

        $filePath = $this->getActiveS3Path($filePath);

        $this->disk->put($filePath, $resizedImage->stream(), 'public');
        
        if($this->fileExists($filePath)) {
            return $filePath;
        }
        return false;
    }

    /**
     * duplicate / copy file from one s3 path to another
     * return true on success, otherwise false
     */
    public function duplicateFile($sourcePath, $destinationPath)
    {
        $destinationPath = $this->getActiveS3Path($destinationPath);
        if($this->fileExists($sourcePath)) {
            $isCopied = $this->disk->copy($sourcePath, $destinationPath);
            if($isCopied) {
                return $destinationPath;
            }
        }
        return false;
    }

    public function storeBase64ToS3($base64, $path, $prefix = '')
    {
        if(!$base64) { return false; }

        $explodedParts = explode(",", $base64);
        if(!isset($explodedParts[1]) || count($explodedParts) < 2) {
            return false;
        }

        $decodedImage = base64_decode($explodedParts[1]);
        $fileName =  $prefix . Str::random(40) . '.jpg';

        $path = $path . $fileName;
        $path = $this->getActiveS3Path($path);
        $this->disk->put($path, $decodedImage);
        if($this->fileExists($path)) {
            return $path;
        }
        return false;
    }

    function storeRemoteImage($url, $path, $prefix = '')
    {
        $contents = file_get_contents($url);
        $fileName =  $prefix . Str::random(40) . '.jpg';

        $path = $path . $fileName;
        $path = $this->getActiveS3Path($path);

        $isStored = $this->disk->put($path, $contents);
        if($this->fileExists($path)) {
            return $path;
        }
        return false;
    }

    public function getFileSize($file)
    {
        if($this->fileExists($file)) {
            return $this->disk->size($file);
        }
        return 0;
    }
}
