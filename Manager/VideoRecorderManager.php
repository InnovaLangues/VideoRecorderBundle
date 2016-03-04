<?php

namespace Innova\VideoRecorderBundle\Manager;

use Claroline\CoreBundle\Persistence\ObjectManager;
use Claroline\CoreBundle\Manager\ResourceManager;
use JMS\DiExtraBundle\Annotation as DI;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Claroline\CoreBundle\Entity\Resource\File;
use Symfony\Component\HttpFoundation\File\File as sFile;
use Symfony\Component\Filesystem\Filesystem;
use Claroline\CoreBundle\Entity\Workspace\Workspace;

/**
 * @DI\Service("innova.video_recorder.manager")
 */
class VideoRecorderManager
{

    protected $rm;
    protected $fileDir;
    protected $tempUploadDir;
    protected $tokenStorage;
    protected $claroUtils;
    protected $container;
    protected $workspaceManager;

    /**
     * @DI\InjectParams({
     *      "container"   = @DI\Inject("service_container"),
     *      "rm"          = @DI\Inject("claroline.manager.resource_manager"),
     *      "fileDir"     = @DI\Inject("%claroline.param.files_directory%"),
     *      "uploadDir"   = @DI\Inject("%claroline.param.uploads_directory%")
     * })
     *
     * @param ResourceManager     $rm
     * @param String              $fileDir
     * @param String              $uploadDir
     */
    public function __construct(ContainerInterface $container, ResourceManager $rm, $fileDir, $uploadDir)
    {
        $this->rm = $rm;
        $this->container = $container;
        $this->fileDir = $fileDir;
        $this->tempUploadDir = $uploadDir;
        $this->tokenStorage = $container->get('security.token_storage');
        $this->claroUtils = $container->get('claroline.utilities.misc');
        $this->workspaceManager = $container->get('claroline.manager.workspace_manager');
    }

    /**
     * Handle web rtc blob file upload, conversion and Claroline File resource creation
     * @param type $postData
     * @param UploadedFile $video
     * @param UploadedFile $audio
     * @param Workspace $workspace
     * @return Claroline File
     */
    public function uploadFileAndCreateResource($postData, UploadedFile $video, UploadedFile $audio = null, Workspace $workspace = null)
    {

        $errors = array();
        // final file upload dir
        $targetDir = '';
        if (!is_null($workspace)) {
            $targetDir = $this->workspaceManager->getStorageDirectory($workspace);
        } else {
            $targetDir = $this->fileDir . DIRECTORY_SEPARATOR . $this->tokenStorage->getToken()->getUsername();
        }
        // if the taget dir does not exist, create it
        $fs = new Filesystem();
        if (!$fs->exists($targetDir)) {
          $fs->mkdir($targetDir);
        }

        // encode the given blob(s) in any case.
        // -> allow the video slider to be effective
        // -> lighter file
        // -> allow the vidéo to be replayed without reloading the web page
        $isFirefox = $postData['nav'] === 'firefox';
        $extension = 'webm';
        $mimeType = 'video/webm';

        if (!$this->validateParams($postData, $video, $isFirefox, $audio)) {
            array_push($errors, 'one or more request parameters are missing.');
            return array('file' => null, 'errors' => $errors);
        }

        // the filename that will be in database (human readable)
        $fileBaseName = $postData['fileName'];
        $uniqueBaseName = $this->claroUtils->generateGuid();
        $finalFileName = $uniqueBaseName . '.' . $extension;

        $baseHashName = $this->getBaseFileHashName($uniqueBaseName, $workspace);
        $hashName = $baseHashName . '.' . $extension;
        // file size after encoding
        $size = 0;

        // marge audio and video in a single webm file for webkit based user agent
        if (!$isFirefox) {

            $tempAudioFileName = $fileBaseName.'.wav';
            $tempVideoFileName = $fileBaseName . '.' . $extension;

            // upload original file in temp upload (ie web/uploads) dir
            $video->move($this->tempUploadDir, $tempVideoFileName);
            $audio->move($this->tempUploadDir, $tempAudioFileName);
            // merge temp files into one webm file
            $cmd = 'avconv -i '. $this->tempUploadDir . DIRECTORY_SEPARATOR . $tempAudioFileName . ' -i ' . $this->tempUploadDir . DIRECTORY_SEPARATOR . $tempVideoFileName . ' -map 0:1 -map 1:0 '. $this->tempUploadDir . DIRECTORY_SEPARATOR . $finalFileName;

            $output;
            $returnVar;
            exec($cmd, $output, $returnVar);

            // cmd error
            if ($returnVar !== 0) {
                array_push($errors, 'File conversion failed with command ' . $cmd . ' and returned ' . $returnVar);
                return array('file' => null, 'errors' => $errors);
            }

            // copy the encoded file to user workspace directory
            $fs->copy($this->tempUploadDir . DIRECTORY_SEPARATOR . $finalFileName, $targetDir . DIRECTORY_SEPARATOR . $finalFileName);
            // get encoded file size...
            $sFile = new sFile($targetDir . DIRECTORY_SEPARATOR . $finalFileName);
            $size = $sFile->getSize();
            // remove temp encoded file
            @unlink($this->tempUploadDir . DIRECTORY_SEPARATOR . $finalFileName);
            // remove original non encoded file from temp dir
            @unlink($this->tempUploadDir . DIRECTORY_SEPARATOR . $tempVideoFileName);
            @unlink($this->tempUploadDir . DIRECTORY_SEPARATOR . $tempAudioFileName);

        } else {

            $tempVideoFileName = $fileBaseName . '.' . $extension;
            $video->move($this->tempUploadDir, $tempVideoFileName);
            // reencode source webm file... into... webm
            $cmd = 'avconv -i '. $this->tempUploadDir . DIRECTORY_SEPARATOR . $tempVideoFileName . ' -c:v libvpx -crf 30 -b:v 512k -c:a libvorbis -ac 1 ' . $this->tempUploadDir . DIRECTORY_SEPARATOR . $finalFileName;

            $output;
            $returnVar;
            exec($cmd, $output, $returnVar);

            // cmd error
            if ($returnVar !== 0) {
                array_push($errors, 'File conversion failed with command ' . $cmd . ' and returned ' . $returnVar);
                return array('file' => null, 'errors' => $errors);
            }

            // copy the encoded file to user workspace directory
            $fs->copy($this->tempUploadDir . DIRECTORY_SEPARATOR . $finalFileName, $targetDir . DIRECTORY_SEPARATOR . $finalFileName);
            // get encoded file size...
            $sFile = new sFile($targetDir . DIRECTORY_SEPARATOR . $finalFileName);
            $size = $sFile->getSize();
            // remove temp encoded file
            @unlink($this->tempUploadDir . DIRECTORY_SEPARATOR . $tempVideoFileName);
        }

        $file = new File();
        $file->setSize($size);
        $file->setName($fileBaseName);
        $file->setHashName($hashName);
        $file->setMimeType($mimeType);

        return array('file' => $file, 'errors' => []);
    }

    private function getBaseFileHashName($uniqueBaseName, Workspace $workspace = null)
    {
        $hashName = '';
        if (!is_null($workspace)) {
            $hashName = 'WORKSPACE_' . $workspace->getId() . DIRECTORY_SEPARATOR . $uniqueBaseName;
        } else {
            $hashName = $this->tokenStorage->getToken()->getUsername() . DIRECTORY_SEPARATOR . $uniqueBaseName;
        }
        return $hashName;
    }

    /**
     * Checks if the data sent by the Ajax Form contain all mandatory fields
     * @param Array  $postData
     * @param UploadedFile  $video the video or video + audio blob sent by webrtc
     * @param Bool $isFirefox
     * @param UploadedFile $audio the audio blob sent by webrtc if chrome has been used
     */
    private function validateParams($postData, UploadedFile $video, $isFirefox, UploadedFile $audio = null)
    {
        $availableNavs = ["firefox", "chrome"];
        if (!array_key_exists('nav', $postData) || $postData['nav'] === '' || !in_array($postData['nav'], $availableNavs)) {
            return false;
        }

        if(!array_key_exists('fileName', $postData) || !isset($postData['fileName']) || $postData['fileName'] === ''){
            return false;
        }

        if (!isset($video) || $video === null || !$video) {
            return false;
        }

        if (!$isFirefox && (!isset($audio) || $audio === null || !$audio)) {
            return false;
        }

        return true;
    }

}
