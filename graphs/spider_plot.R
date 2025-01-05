# Install and load required packages
if (!requireNamespace("fmsb", quietly = TRUE)) install.packages("fmsb")
if (!requireNamespace("scales", quietly = TRUE)) install.packages("scales") # Ensure that 'alpha' is available
library(fmsb)
library(scales)

# Read the CSV file
data <- read.csv("csv/results_total_metrics.csv", sep=";")

# Set row names as the first column (Tool)
row.names(data) <- data[, 1]

# Remove the first column after setting row names
data <- data[, -1]

# Function to normalize a vector
normalize <- function(x) {
  (x - min(x)) / (max(x) - min(x))
}

# Apply normalization to the second last and last columns
data[, ncol(data) - 1] <- normalize(data[, ncol(data) - 1])
data[, ncol(data)] <- normalize(data[, ncol(data)])

# Add max and min rows required by fmsb
data_with_limits <- rbind(rep(1, ncol(data)), rep(0, ncol(data)), data)

# Set up the plot
par(mar=c(1, 1, 1, 1))
colors <- c("red", "blue", "green", "orange")

# Set the file path for the PDF
pdf_file <- "pdf/spider_plot.pdf"

# Open a PDF device
pdf(pdf_file, width = 16, height = 9)

# Create the spider plot
radarchart(data_with_limits,
           pcol=colors[1:(nrow(data_with_limits) - 2)],
           pfcol=alpha(colors[1:(nrow(data_with_limits) - 2)], 0.2), # Transparent fill
           plty=2, # Dotted lines for all outlines
           plwd=2,
           cglcol="grey",
           cglty=1,
           axislabcol="grey",
           caxislabels=seq(0, 1, 0.2),
           cglwd=0.8,
           vlcex=0.8,
           axistype=1, # Show axis labels
           seg=5, # 5 segments on each axis
           pdensity=NULL, # Remove dots at corners
           pangle=NULL, # Remove dots at corners
           pch=NA) # Remove dots at the end of lines

# Add a legend with squares
legend("topright",
       legend=row.names(data_with_limits)[-c(1, 2)], # Exclude max and min rows for legend
       bty="n",
       pch=15, # Use squares for legend symbols
       col=colors[1:(nrow(data_with_limits) - 2)], # Match the number of data sets
       pt.cex=1.5, # Adjust size of squares
       text.col="black",
       cex=0.8) # Adjust text size if needed

# Print the scale
axis_labels <- colnames(data_with_limits)
text(x=0, y=-1.3, labels=paste("Scale:", paste(axis_labels, collapse=", ")), cex=0.8)

# Save the plot (close the PDF device)
dev.off()