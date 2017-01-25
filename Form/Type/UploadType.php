<?php

namespace Ruvents\ReformBundle\Form\Type;

use Ruvents\ReformBundle\MockUploadedFile;
use Ruvents\ReformBundle\Upload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UploadType extends AbstractType
{
    /**
     * @var string
     */
    private $defaultTmpDir;

    /**
     * @var FormInterface[][]
     */
    private $formsByRootFormHash = [];

    /**
     * @param string $defaultTmpDir
     */
    public function __construct($defaultTmpDir)
    {
        $this->defaultTmpDir = $defaultTmpDir;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', $options['name_type'], $options['name_options'])
            ->add('file', $options['file_type'], $options['file_options'])
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
                $form = $event->getForm();
                $data = $event->getData();

                $name = empty($data['name']) ? null : $data['name'];
                $file = isset($data['file']) && $data['file'] instanceof UploadedFile ? $data['file'] : null;
                $tmpDir = $form->getConfig()->getOption('tmp_dir');
                $dataClass = $form->getConfig()->getOption('data_class');

                if (!$name && !$file) {
                    return;
                }

                if (!$file && !$data['file'] = $this->getMockUploadedFile($name, $tmpDir)) {
                    return;
                }

                if (!$name) {
                    $data['name'] = sha1(uniqid(get_class($this)));
                }

                $this->registerUploadForm($form);
                $form->setData(new $dataClass);
                $event->setData($data);
            });
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver
            ->setDefaults([
                'data_class' => Upload::class,
                'empty_data' => null,
                'label' => false,
                'name_type' => HiddenType::class,
                'name_options' => [],
                'file_type' => FileType::class,
                'file_options' => [],
                'tmp_dir' => $this->defaultTmpDir,
            ])
            ->setAllowedTypes('name_type', 'string')
            ->setAllowedTypes('name_options', 'array')
            ->setAllowedTypes('file_type', 'string')
            ->setAllowedTypes('file_options', 'array')
            ->setAllowedTypes('tmp_dir', 'string');
    }

    /**
     * @param FormInterface $rootForm
     */
    public function processValidatedRootForm(FormInterface $rootForm)
    {
        $hash = $this->getFormHash($rootForm);

        if (empty($this->formsByRootFormHash[$hash])) {
            return;
        }

        foreach ($this->formsByRootFormHash[$hash] as $form) {
            $upload = $form->getData();

            if ($form->isValid() && $upload instanceof Upload && $upload->getName() && $upload->getFile()) {
                $this->saveUploadedFile(
                    $upload->getFile(),
                    $upload->getName(),
                    $form->getConfig()->getOption('tmp_dir')
                );
            }
        }
    }

    /**
     * @param string $name
     * @param string $path
     *
     * @return null|MockUploadedFile
     */
    private function getMockUploadedFile($name, $path)
    {
        $pathname = rtrim($path, '/').'/'.$name;

        if (!is_file($pathname)) {
            return null;
        }

        $metaPathname = $pathname.'.json';
        $meta = is_file($pathname)
            ? json_decode(file_get_contents($metaPathname), true)
            : [];

        return new MockUploadedFile(
            $pathname,
            isset($meta['originalName']) ? $meta['originalName'] : basename($pathname),
            isset($meta['mimeType']) ? $meta['mimeType'] : null,
            isset($meta['size']) ? $meta['size'] : null
        );
    }

    /**
     * @param UploadedFile $uploadedFile
     * @param string       $name
     * @param string       $path
     */
    private function saveUploadedFile(UploadedFile $uploadedFile, $name, $path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $metaPathname = rtrim($path, '/').'/'.$name.'.json';
        $meta = [
            'originalName' => $uploadedFile->getClientOriginalName(),
            'mimeType' => $uploadedFile->getClientMimeType(),
            'size' => $uploadedFile->getClientSize(),
        ];

        file_put_contents($metaPathname, json_encode($meta));

        $uploadedFile->move($path, $name);
    }

    /**
     * @param FormInterface $uploadForm
     */
    private function registerUploadForm(FormInterface $uploadForm)
    {
        $rootForm = $uploadForm;

        while (!$rootForm->isRoot()) {
            $rootForm = $rootForm->getParent();
        }

        $hash = $this->getFormHash($rootForm);
        $this->formsByRootFormHash[$hash][] = $uploadForm;
    }

    /**
     * @param FormInterface $form
     *
     * @return string
     */
    private function getFormHash(FormInterface $form)
    {
        return spl_object_hash($form);
    }
}
