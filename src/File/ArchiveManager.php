<?php
namespace Omeka\File;

use Omeka\File\Store\StoreInterface;
use Omeka\File\Thumbnailer\ThumbnailerInterface;
use Omeka\Entity\Media;
use Omeka\File\Manager;
use Zend\ServiceManager\ServiceLocatorInterface;

class ArchiveManager extends Manager
{

    protected $media;
    protected $moduleObject = null;

    public function __construct(array $config, $tempDir, ServiceLocatorInterface $serviceLocator)
    {
        parent::__construct($config,$tempDir,$serviceLocator);
        $this->moduleObject = $serviceLocator
            ->get('ModuleManager')->getModule('ArchiveRepertory');


    }

    public function setMedia($media) {
        $this->media=$media;
        return $this;
    }


    protected function _getItemFolderName($item)
    {
        if (!($folder = $this->moduleObject->getOption('archive_repertory_item_folder')))
            return '';

        switch ($folder) {
            case 'id':
                return (string) $item->getId();
            case 'none':
            case '':
                return '';
            default:
                $name = $this->_getRecordFolderNameFromMetadata(
                                                                $item,
                                                                $folder
                );

        }

        return $this->moduleObject->_convertFilenameTo($name, $this->moduleObject->getOption('archive_repertory_item_convert')) ;
    }

    protected function _getRecordFolderNameFromMetadata($record, $elementId)
    {
        xdebug_break();

        $identifier = $this->moduleObject->_getRecordIdentifiers($record, null, true);

        return empty($identifier)
            ? (string) $record->getId()
            : $this->moduleObject->_sanitizeName($identifier);
    }


    public function getStoragePath($prefix, $name, $extension = null)
    {

        if ($this->media) {
            $prefix=($prefix ? $prefix.'/' : ''). ($this->_getItemFolderName($this->media->getItem()));
        }
        return sprintf('%s/%s%s', $prefix, $name, $extension ? ".$extension" : null);
    }


    public function getStorageName(File $file)
    {

        $extension = $this->getExtension($file);
        if ($this->media) {

            if ($this->moduleObject->getOption('archive_repertory_file_keep_original_name') === true)  {
                return $file->getSourceName();
            }
        }

        $storageName = sprintf('%s%s', $file->getStorageBaseName(),
            $extension ? ".$extension" : null);

        return $storageName;
    }


    protected function _getDerivativeFilename($filename, $defaultExtension, $derivativeType = null)
    {
        $base = pathinfo($filename, PATHINFO_EXTENSION) ? substr($filename, 0, strrpos($filename, '.')) : $filename;
        $fullExtension = !is_null($derivativeType) && isset($this->_derivativeExtensionsByType[$derivativeType])
            ? $this->_derivativeExtensionsByType[$derivativeType]
            : '.' . $defaultExtension;
        return $base . $fullExtension;
    }

    protected function _getDerivativeExtension($file)
    {
        return $file->has_derivative_image ? pathinfo($file->getDerivativeFilename(), PATHINFO_EXTENSION) : '';
    }


}
