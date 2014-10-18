#!/bin/bash

read -p "请输入备注：" commit_content

git pull
echo "pull OK\n"
git add -A *
echo "add OK\n"
git commit -m "$commit_content"
echo "commit OK\n"
git push origin master
echo "push OK\n"
exit 0
