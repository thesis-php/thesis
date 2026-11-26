<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Test;
use Thesis\Dispatcher;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Status;
use Thesis\Net\UnixAddress;

#[Test]
final class StaticFilesTest
{
    public static function followsAReplacedRootDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/document-root-' . bin2hex(random_bytes(8));
        mkdir($directory);
        mkdir($directory . '/first');
        mkdir($directory . '/second');
        $address = new UnixAddress('');
        $context = new Context($address, $address, null, new DefaultErrorHandler(), new Dispatcher());

        try {
            file_put_contents($directory . '/first/message', 'old');
            file_put_contents($directory . '/second/message', 'new release');
            symlink('first', $directory . '/current');
            $root = new StaticFiles($directory . '/current');
            $get = new Request(Method::GET, '/message');
            Assert::same($root($get, $context)->takeBody()->readAll(), 'old');

            symlink('second', $directory . '/next');
            rename($directory . '/next', $directory . '/current');
            $head = $root(new Request(Method::HEAD, '/message'), $context);
            Assert::same($head->headers->get('Content-Length'), '11');
            Assert::same($root($get, $context)->takeBody()->readAll(), 'new release');
        } finally {
            @unlink($directory . '/next');
            @unlink($directory . '/current');
            @unlink($directory . '/first/message');
            @unlink($directory . '/second/message');
            rmdir($directory . '/first');
            rmdir($directory . '/second');
            rmdir($directory);
        }
    }

    public static function servesCurrentFilesUnderTheMountedDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/document-root-' . bin2hex(random_bytes(8));
        mkdir($directory);
        mkdir($directory . '/public');
        $address = new UnixAddress('');
        $context = new Context($address, $address, null, new DefaultErrorHandler(), new Dispatcher());

        $types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'unknown' => 'application/octet-stream',
        ];

        try {
            $root = new StaticFiles($directory);
            foreach ($types as $extension => $type) {
                $name = "public/example.{$extension}";
                file_put_contents($directory . '/' . $name, 'first');
                $uri = '/public/%65xample.' . $extension . '?version=1';
                $get = $root(new Request(Method::GET, $uri), $context);
                Assert::same($get->headers->get('Content-Type'), $type);
                $body = $get->takeBody();
                Assert::false($body->isBuffered);
                Assert::same($body->size, 5);
                Assert::same($body->read(2), 'fi');

                file_put_contents($directory . '/replacement', 'second');
                rename($directory . '/replacement', $directory . '/' . $name);
                Assert::same($body->read(8), 'rst');
                Assert::null($body->read(8));
                $body->close();
                $head = $root(new Request(Method::HEAD, $uri), $context);
                Assert::same($head->headers->get('Content-Length'), '6');
                Assert::same($head->headers->get('Content-Type'), $type);
                Assert::same($head->takeBody()->readAll(), '');
                Assert::same($root(new Request(Method::GET, $uri), $context)->takeBody()->readAll(), 'second');
                unlink($directory . '/' . $name);
            }

            $router = new Router([$root->route('/static')]);
            symlink(__FILE__, $directory . '/outside');
            file_put_contents($directory . '/replacement', 'mounted');
            $get = $router(new Request(Method::GET, '/static/replacement'), $context);
            Assert::same($get->takeBody()->readAll(), 'mounted');
            $head = $router(new Request(Method::HEAD, '/static/%72eplacement'), $context);
            Assert::same($head->headers->get('Content-Length'), '7');
            $miss = $router(new Request(Method::GET, '/replacement'), $context);
            Assert::same($miss->status, Status::NOT_FOUND);

            $post = $root(new Request(Method::POST, '/static/file'), $context);
            Assert::same($post->status, Status::METHOD_NOT_ALLOWED);
            Assert::same($post->headers->get('Allow'), 'GET, HEAD');

            foreach (['outside', 'public', 'missing'] as $path) {
                Assert::same($root(new Request(Method::GET, '/' . $path), $context)->status, Status::NOT_FOUND);
            }
        } finally {
            @unlink($directory . '/outside');
            foreach (array_keys($types) as $extension) {
                @unlink($directory . '/public/example.' . $extension);
            }
            @unlink($directory . '/replacement');
            rmdir($directory . '/public');
            rmdir($directory);
        }
    }
}
