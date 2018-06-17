<?php
/*
 * Archive Repertory
 *
 * Keeps original names of files and put them in a hierarchical structure.
 *
 * Copyright Daniel Berthereau 2012-2017
 * Copyright BibLibre, 2016
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */
namespace ArchiveRepertory;

use ArchiveRepertory\Form\ConfigForm;
use Omeka\Entity\Media;
use Omeka\Module\AbstractModule;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\Controller\AbstractController;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $this->manageSettings($serviceLocator->get('Omeka\Settings'), 'install');
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $this->manageSettings($serviceLocator->get('Omeka\Settings'), 'uninstall');
    }

    protected function manageSettings($settings, $process, $key = 'config')
    {
        $config = require __DIR__ . '/config/module.config.php';
        $defaultSettings = $config[strtolower(__NAMESPACE__)][$key];
        foreach ($defaultSettings as $name => $value) {
            switch ($process) {
                case 'install':
                    $settings->set($name, $value);
                    break;
                case 'uninstall':
                    $settings->delete($name);
                    break;
            }
        }
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        if (version_compare($oldVersion, '3.14.0', '<')) {
            $settings = $serviceLocator->get('Omeka\Settings');
            $config = require __DIR__ . '/config/module.config.php';
            $defaultSettings = $config[strtolower(__NAMESPACE__)]['config'];

            $settings->set('archiverepertory_item_set_folder',
                $defaultSettings['archiverepertory_item_set_folder']);
            $settings->set('archiverepertory_item_set_prefix',
                $defaultSettings['archiverepertory_item_set_prefix']);
            $settings->set('archiverepertory_item_set_convert',
                $defaultSettings['archiverepertory_item_set_convert']);

            $itemConvert = strtolower($settings->get['archive_repertory_item_convert']);
            if ($itemConvert == 'keep name') {
                $itemConvert = 'keep';
            }
            $settings->set('archiverepertory_item_convert', $itemConvert);

            $mediaConvert = $settings->get('archive_repertory_file_keep_original_name')
                ? $defaultSettings['archiverepertory_media_convert']
                : 'hash';
            $settings->set('archiverepertory_media_convert', $mediaConvert);
            $settings->delete('archive_repertory_file_keep_original_name');

            $settings->delete('archive_repertory_derivative_folders');
        }

        if (version_compare($oldVersion, '3.15.3', '<')) {
            $settings = $serviceLocator->get('Omeka\Settings');
            $config = include __DIR__ . '/config/module.config.php';
            foreach ($config[strtolower(__NAMESPACE__)]['config'] as $name => $value) {
                $oldName = str_replace('archiverepertory_', 'archive_repertory_', $name);
                $settings->set($name, $settings->get($oldName, $value));
                $settings->delete($oldName);
            }
            $settings->delete('archive_repertory_ingesters');
        }
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $data = [];
        $defaultSettings = $config[strtolower(__NAMESPACE__)]['config'];
        foreach ($defaultSettings as $name => $value) {
            $data[$name] = $settings->get($name);
        }

        $form->init();
        $form->setData($data);

        return $renderer->render('archive-repertory/module/config', [
            'form' => $form,
        ]);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $settings = $services->get('Omeka\Settings');

        $params = $controller->getRequest()->getPost();

        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $defaultSettings = $config[strtolower(__NAMESPACE__)]['config'];
        foreach ($params as $name => $value) {
            if (array_key_exists($name, $defaultSettings)) {
                $settings->set($name, $value);
            }
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'afterSaveItem'],
            100
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$this, 'afterSaveItem'],
            100
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.delete.post',
            [$this, 'afterDeleteItem'],
            100
        );
    }

    /**
     * Manages folders for attached files of items.
     */
    public function afterSaveItem(Event $event)
    {
        $item = $event->getParam('response')->getContent();
        foreach ($item->getMedia() as $media) {
            $this->afterSaveMedia($media);
        }
    }

    /**
     * Set medias at the right place.
     *
     * @param Media $media
     */
    protected function afterSaveMedia(Media $media)
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $config = $services->get('Config');
        $fileManager = $services->get('ArchiveRepertory\FileManager');
        $fileWriter = $services->get('ArchiveRepertory\FileWriter');

        $ingesters = $config['archiverepertory']['ingesters'];
        $ingester = $media->getIngester();
        if (!isset($ingesters[$ingester])) {
            return;
        }

        // Check if the file should be moved (so change its storage id).
        $currentStorageId = $media->getStorageId();
        $newStorageId = $fileManager->getStorageId($media);
        if ($currentStorageId == $newStorageId) {
            return;
        }

        $extension = $media->getExtension();
        $newFilename = $extension ? $newStorageId . '.' . $extension : $newStorageId;

        // Check if the original file exists, else this is an undetected
        // error during the convert process.
        $path = $fileManager->getFullArchivePath('original');
        $filepath = $fileManager->concatWithSeparator($path, $media->getFilename());
        if (!$fileWriter->fileExists($filepath)) {
            $msg = $this->translate('This file is not present in the original directory : ' . $filepath); // @translate
            $msg .= ' ' . $this->translate('There was an undetected error before storage, probably during the convert process.'); // @translate
            $this->addError($msg);
            return;
        }

        $result = $fileManager->moveFilesInArchiveFolders(
            $media->getFilename(),
            $newFilename
        );

        if (!$result) {
            $msg = $this->translate('Cannot move files inside archive directory.'); // @translate
            $this->addError($msg);
            return;
        }

        // Update file in Omeka database immediately for each file.
        // Because this is not a file hook, the file is not automatically saved,
        // so persist and flush are required now.
        $media->setStorageId($newStorageId);
        $entityManager->persist($media);
        $entityManager->flush();
    }

    /**
     * Remove folders for attached files of items.
     */
    public function afterDeleteItem(Event $event)
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $config = $services->get('Config');
        $fileManager = $services->get('ArchiveRepertory\FileManager');

        $item = $event->getParam('response')->getContent();
        $ingesters = $config['archiverepertory']['ingesters'];

        // Check if a folder was added without checking settings, because they
        // could change.
        foreach ($item->getMedia() as $media) {
            $ingester = $media->getIngester();
            if (!isset($ingesters[$ingester])) {
                continue;
            }

            // Check if there is a directory to remove. Note: only the "/" is
            // used during the saving.
            $filename = $media->getFilename();
            if (strpos($filename, '/') === false) {
                continue;
            }
            $storageDir = dirname($filename);
            $fileManager->removeArchiveFolders($storageDir);
            // Whatever the result, continue the other medias.
        }
    }

    protected function addError($msg)
    {
        $messenger = new Messenger;
        $messenger->addError($msg);
    }

    protected function translate($string)
    {
        $serviceLocator = $this->getServiceLocator();
        return $serviceLocator->get('MvcTranslator')->translate($string);
    }
}
