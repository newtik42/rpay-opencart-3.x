#!/bin/bash
echo $PWD
# Отримуємо назву поточної папки
current_folder=$(basename "$PWD")

# Перевірка наявності папки "upload"
if [ ! -d "upload" ]; then
  echo "Папка 'upload' не знайдена."
  exit 1
fi

# Створюємо ZIP-архів з усіх файлів у папці "upload" та файлу "install.xml"
zip_name="$current_folder.ocmod.zip"
zip -r "$zip_name" upload install.xml

echo "Створено ZIP-архів. Архів збережено у файлі '$zip_name'."
